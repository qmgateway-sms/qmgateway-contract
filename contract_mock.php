<?php
declare(strict_types=1);
/**
 * A pretend QMGateway API for SDK tests. Start it with PHP's built-in server:
 *
 *   php -S 127.0.0.1:8120 contract_mock.php
 *
 * The scenario is part of the address, so ANY SDK can use it by changing only its base URL:
 *
 *   http://127.0.0.1:8120/__scenario/NAME/s-SESSION/api/v1/messages      NAME is a scenario from the contract JSON files, SESSION any short id you choose
 *   http://127.0.0.1:8120/__scenario/NAME/s-SESSION/__log                what the mock received (JSON), to check retries and idempotency keys
 *
 * Without a scenario it answers every endpoint with the example from the OpenAPI file (scenario "ok").
 * This file is for development only: it refuses to run on a public address.
 */
if (!in_array($_SERVER['SERVER_NAME'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true)) { http_response_code(404); exit; }
$contract = __DIR__;   // next to the JSON when shipped inside the contract folder
$spec = json_decode((string)@file_get_contents("$contract/openapi.json"), true) ?: [];
$retry = json_decode((string)@file_get_contents("$contract/retry-cases.json"), true)['scenarios'] ?? [];
$pages = json_decode((string)@file_get_contents("$contract/pagination-cases.json"), true)['cases'] ?? [];

$uri = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scenario = 'ok'; $session = 'default';
if (preg_match('#^/__scenario/([a-z_0-9]+)(?:/s-([a-z0-9]+))?(/.*)$#', $uri, $m)) { $scenario = $m[1]; $session = $m[2] ?: 'default'; $path = $m[3]; } else { $path = $uri; }
$state = sys_get_temp_dir() . '/qm_contract_' . $scenario . '_' . $session . '.json';
$log = is_file($state) ? (json_decode((string)file_get_contents($state), true) ?: []) : [];
header('Content-Type: application/json');

if ($path === '/__log') { echo json_encode($log); exit; }
if ($path === '/__reset') { @unlink($state); echo '{"reset":true}'; exit; }

$hdrs = function_exists('getallheaders') ? getallheaders() : [];
$log[] = ['n' => count($log) + 1, 'method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'query' => $_GET, 'at' => microtime(true),
    'idempotency_key' => $hdrs['Idempotency-Key'] ?? $hdrs['idempotency-key'] ?? null, 'api_key_sent' => isset($hdrs['X-API-Key']) || isset($hdrs['x-api-key']), 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null];
file_put_contents($state, json_encode($log), LOCK_EX);
$n = count($log);   // this is request number $n of the scenario

$respond = static function (int $status, array $body, array $headers = []): never {
    http_response_code($status);
    foreach ($headers as $k => $v) header("$k: $v");
    header('X-Request-ID: mock' . bin2hex(random_bytes(6))); header('X-RateLimit-Limit: 120'); header('X-RateLimit-Remaining: 100'); header('X-RateLimit-Reset: ' . (time() + 30));
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
};
$errorBody = static fn(string $code, string $message): array => ['success' => false, 'error' => ['code' => $code, 'message' => $message, 'request_id' => 'mock' . bin2hex(random_bytes(4))]];
$example = static function (string $method, string $apiPath) use ($spec): array {
    $op = $spec['paths'][$apiPath][strtolower($method)] ?? null;
    if (!$op) return [404, ['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => "No such endpoint in the mock: $apiPath"]]];
    foreach ($op['responses'] as $code => $r) if ((int)$code >= 200 && (int)$code < 300) return [(int)$code, $r['content']['application/json']['examples']['example1']['value'] ?? ['success' => true]];
    return [200, ['success' => true]];
};

// ---- retry scenarios: the n-th request gets the n-th planned answer; the last one repeats
foreach ($retry as $s) {
    if ($s['name'] !== $scenario) continue;
    $plan = $s['answers']; $step = ($s['repeat'] ?? '') === 'forever' ? $plan[0] : $plan[min($n, count($plan)) - 1];
    $status = (int)$step['status'];
    if ($status >= 200 && $status < 300) { [$st, $body] = $example($_SERVER['REQUEST_METHOD'], preg_replace('#^.*?(/api/.*)$#', '$1', $path)); $respond($status, $body); }
    $codes = [422 => ['VALIDATION_ERROR', 'Invalid phone number.'], 429 => ['RATE_LIMIT_EXCEEDED', 'Too many requests.'], 503 => ['SERVICE_UNAVAILABLE', 'Please try again shortly.']];
    [$code, $msg] = $codes[$status] ?? ['ERROR', 'Error'];
    $respond($status, $errorBody($code, $msg), $step['headers'] ?? []);
}
// ---- pagination scenarios: pages of ids linked by the next-page value
foreach ($pages as $p) {
    if ($p['name'] !== $scenario) continue;
    $param = $p['param']; $cursor = isset($_GET[$param]) ? (int)$_GET[$param] : null;
    $idx = 0;
    // the cursor is the last id of the previous page: find the page after it
    if ($cursor !== null) { $idx = null; foreach ($p['pages'] as $i => $ids) if ($ids && end($ids) === $cursor) { $idx = $i + 1; break; } if ($idx === null || !isset($p['pages'][$idx])) $respond(200, ['success' => true, 'data' => [], $p['next_field'] => null]); }
    $ids = $p['pages'][$idx]; $next = isset($p['pages'][$idx + 1]) && $ids ? end($ids) : null;
    $items = array_map(static fn($id) => $p['operationId'] === 'get_inbox' ? ['id' => $id, 'from' => '+265991234567', 'message' => "reply $id", 'received_at' => '2026-11-26 10:00:00'] : ['id' => $id, 'phone_number' => '+265991234567', 'status' => 'Delivered', 'message_type' => 'transactional', 'sms_units' => 1, 'scheduled_time' => null, 'created_at' => '2026-11-26 10:00:00', 'sent_at' => null, 'delivered_at' => null, 'failed_at' => null, 'failure_reason' => null], $ids);
    $respond(200, ['success' => true, 'data' => $items, $p['next_field'] => $next] + ($p['operationId'] === 'get_inbox' ? ['receives' => true] : []));
}
// ---- default: the documented example for the endpoint
[$status, $body] = $example($_SERVER['REQUEST_METHOD'], $path);
$respond($status, $body);
