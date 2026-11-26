<?php
declare(strict_types=1);
/**
 * Plays cases.json against a running QMGateway and checks every answer against openapi.json.
 * It proves the live API still matches what the documentation, the OpenAPI file and every SDK promise.
 *
 *   php contract_check.php --base=https://... --live=qm_live_... --test=qm_test_...   against any server you have keys for
 *
 * Exit code 0 = everything matched, 1 = something did not.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opt = []; foreach (array_slice($argv, 1) as $a) { if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = $m[2] ?? '1'; }
$contract = __DIR__;
foreach (['cases.json', 'openapi.json', 'webhook-cases.json'] as $f) if (!is_file("$contract/$f")) { fwrite(STDERR, "Missing $f next to this script.\n"); exit(2); }
$cases = json_decode((string)file_get_contents("$contract/cases.json"), true, 512, JSON_THROW_ON_ERROR);
$spec = json_decode((string)file_get_contents("$contract/openapi.json"), true, 512, JSON_THROW_ON_ERROR);
$wh = json_decode((string)file_get_contents("$contract/webhook-cases.json"), true, 512, JSON_THROW_ON_ERROR);

// ---------------------------------------------------------------- a small JSON Schema checker for the subset the OpenAPI file uses
function resolveRef(array $spec, string $ref): array { $n = $spec; foreach (explode('/', ltrim($ref, '#/')) as $part) $n = $n[$part]; return $n; }
/** @return list<string> problems, empty when the value fits */
function check(array $spec, mixed $v, array $schema, string $at = '$'): array
{
    if (isset($schema['$ref'])) $schema = resolveRef($spec, $schema['$ref']);
    if ($v === null) return !empty($schema['nullable']) ? [] : ["$at is null but the schema does not allow it"];
    $type = $schema['type'] ?? null; $errs = [];
    $actual = match (true) { is_bool($v) => 'boolean', is_int($v) => 'integer', is_float($v) => 'number', is_string($v) => 'string', is_array($v) => (array_is_list($v) ? 'array' : 'object'), default => 'unknown' };
    if ($type === 'object' && $v === [] ) $actual = 'object';
    if ($type !== null && $type !== $actual && !($type === 'number' && $actual === 'integer')) return ["$at should be $type but is $actual"];
    if (isset($schema['enum']) && !in_array($v, $schema['enum'], true)) $errs[] = "$at is not one of the allowed values";
    if ($type === 'object') {
        foreach ($schema['required'] ?? [] as $k) if (!array_key_exists($k, $v)) $errs[] = "$at.$k is missing";
        foreach ($schema['properties'] ?? [] as $k => $s) if (array_key_exists($k, $v)) $errs = array_merge($errs, check($spec, $v[$k], is_array($s) ? $s : [], "$at.$k"));
    }
    if ($type === 'array' && is_array($schema['items'] ?? null) && $schema['items']) foreach ($v as $i => $item) $errs = array_merge($errs, check($spec, $item, $schema['items'], "{$at}[$i]"));
    return $errs;
}
function pluck(array $data, string $path): mixed { foreach (explode('.', $path) as $p) { if (!is_array($data) || !array_key_exists(ctype_digit($p) ? (int)$p : $p, $data)) return null; $data = $data[ctype_digit($p) ? (int)$p : $p]; } return $data; }

$base = rtrim((string)($opt['base'] ?? ''), '/');
if ($base === '' || empty($opt['live'])) { fwrite(STDERR, "Give --base=URL --live=KEY [--test=KEY]\n"); exit(2); }
$keys = ['live' => $opt['live'], 'test' => $opt['test'] ?? '', 'wrong' => 'qm_live_' . str_repeat('0', 48), 'none' => ''];

// ---------------------------------------------------------------- run the API cases
$saved = ['unique' => 'contract-' . bin2hex(random_bytes(4)), 'in_three_days' => date('c', time() + 3 * 86400), 'in_four_days' => date('c', time() + 4 * 86400)];
$sub = static function (mixed $v) use (&$saved): mixed {
    if (is_string($v)) { return preg_replace_callback('/\{\{(\w+)\}\}/', static fn($m) => (string)($saved[$m[1]] ?? $m[0]), $v); }
    if (is_array($v)) { $o = []; foreach ($v as $k => $x) $o[$k] = (is_string($x) && preg_match('/^\{\{(\w+)\}\}$/', $x, $m) && array_key_exists($m[1], $saved) && !in_array($m[1], ['unique', 'same_unique', 'in_three_days', 'in_four_days'], true)) ? $saved[$m[1]] : (is_array($x) || is_string($x) ? (function () use ($x, &$o) { return null; })() : $x); return $v; }
    return $v;
};
$deep = static function (mixed $v) use (&$deep, &$saved): mixed {   // replaces {{name}} everywhere; a whole-string placeholder keeps the saved value's type (an id stays a number)
    if (is_string($v)) { if (preg_match('/^\{\{(\w+)\}\}$/', $v, $m) && array_key_exists($m[1], $saved)) return $saved[$m[1]]; return preg_replace_callback('/\{\{(\w+)\}\}/', static fn($m) => (string)($saved[$m[1]] ?? $m[0]), $v); }
    if (is_array($v)) return array_map($deep, $v);
    return $v;
};
$pass = 0; $fail = 0; $report = [];
$lastUnique = '';
foreach ($cases['cases'] as $c) {
    $name = $c['id'] . ' - ' . $c['title'];
    $query = $c['query'] ?? []; $body = isset($c['body']) ? $deep($c['body']) : null; $query = $deep($query);
    $headers = []; foreach ($c['headers'] ?? [] as $hk => $hv) { if ($hv === '{{same_unique}}') $hv = $lastUnique; elseif ($hv === '{{unique}}') { $hv = $lastUnique = 'contract-' . bin2hex(random_bytes(6)); } $headers[] = "$hk: $hv"; }
    $key = $keys[$c['key'] ?? 'live'] ?? '';
    $ch = curl_init($base . $c['path'] . ($query ? '?' . http_build_query($query) : ''));
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $c['method'], CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_merge($key !== '' ? ['X-API-Key: ' . $key] : [], $body !== null ? ['Content-Type: application/json'] : [], $headers), CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null]);
    $raw = (string)curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $hdr = strtolower(substr($raw, 0, $hs)); $json = json_decode(substr($raw, $hs), true);
    $problems = [];
    if ($status !== $c['expect']['status']) $problems[] = "HTTP $status, expected " . $c['expect']['status'];
    if (!is_array($json)) $problems[] = 'the answer is not JSON';
    else {
        $problems = array_merge($problems, check($spec, $json, ['$ref' => $c['expect']['schema']]));
        if (isset($c['expect']['error_code']) && pluck($json, 'error.code') !== $c['expect']['error_code']) $problems[] = 'error.code is ' . json_encode(pluck($json, 'error.code')) . ', expected ' . $c['expect']['error_code'];
        foreach ($c['expect_equal'] ?? [] as $path => $want) if (pluck($json, $path) !== $want) $problems[] = "$path is " . json_encode(pluck($json, $path)) . ', expected ' . json_encode($want);
        foreach ($c['save'] ?? [] as $var => $path) $saved[$var] = pluck($json, $path);
    }
    foreach ($cases['headers_every_answer'] as $h) if (!str_contains($hdr, strtolower($h) . ':') && !($c['key'] ?? 'live') === 'none') $problems[] = "header $h is missing";
    if (str_contains($hdr, 'set-cookie:')) $problems[] = 'the API set a cookie (it must be stateless)';
    $ok = !$problems; $ok ? $pass++ : $fail++;
    $report[] = [$ok, $name, $problems];
}
foreach ($report as [$ok, $name, $problems]) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; foreach ($problems as $p) echo "       - $p\n"; }

// ---------------------------------------------------------------- the webhook cases, against a reference verifier (the one the PHP SDK will use)
$verify = static function (array $headers, string $body, string $secret, int $now, int $tolerance): array {
    $ts = $headers['X-QMGateway-Timestamp'] ?? null; $sig = $headers['X-QMGateway-Signature'] ?? null;
    if ($ts === null || $sig === null) return [false, 'missing_header'];
    $expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
    if (!hash_equals($expected, $sig)) return [false, 'bad_signature'];
    if (!ctype_digit($ts) || abs($now - (int)$ts) > $tolerance) return [false, 'stale_timestamp'];
    return [true, 'ok'];
};
foreach ($wh['cases'] as $w) {
    [$okv, $why] = $verify($w['headers'], $w['body'], $wh['secret'], $wh['now'], $wh['tolerance_seconds']);
    $good = $okv === $w['expect_verified'] && ($okv || $why === $w['reason'] || $w['reason'] === 'stale_timestamp' && in_array($why, ['stale_timestamp', 'bad_signature'], true));
    $good ? $pass++ : $fail++;
    echo ($good ? 'PASS ' : 'FAIL ') . 'webhook - ' . $w['title'] . ($good ? '' : " (got $why)") . "\n";
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
