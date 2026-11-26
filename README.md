# QMGateway SDK contract

The shared test data every QMGateway SDK (PHP, Node.js, Python, .NET) must pass. It is **generated**
from the QMGateway API definition, so it cannot disagree with the API. Do not edit these files by hand: they are replaced whenever the API changes.

API version: **1.0**

| File | Use it to test |
|---|---|
| `openapi.json` | Types and field names. Every SDK model must have the fields of the matching `...Response` schema. Optional fields must not break parsing, and unknown extra fields must be ignored. |
| `cases.json` | The ordered requests every SDK must be able to make, the status and shape of each answer, and the typed error each failing case must raise. |
| `webhook-cases.json` | The signature verifier: a secret, a fixed "now", and cases that must be accepted or rejected. |
| `retry-cases.json` | The retry rules (attempts, waits, same idempotency key). Played by the mock server. |
| `pagination-cases.json` | The auto-pagination iterators: every item once, in order, stop on `null`. |
| `contract_mock.php` | A mock server for the retry and pagination scenarios (localhost only). |
| `contract_check.php` | A runner that plays `cases.json` against a real server and checks every answer against `openapi.json`. |

## Use it from an SDK repository

CI clones this repository next to the tests:

```
git clone --depth 1 https://github.com/qmgateway-sms/qmgateway-contract.git tests/contract
```

Each SDK's test runner reads the files from `tests/contract` (or the folder in `QMGATEWAY_CONTRACT_DIR`).

## The mock server

```
php -S 127.0.0.1:8120 contract_mock.php
```

Point the SDK at `http://127.0.0.1:8120/__scenario/NAME/s-SESSION`, where `NAME` is a scenario in `retry-cases.json` or `pagination-cases.json` and
`SESSION` is any short id. `GET .../__log` returns what the mock received. Without a scenario it answers with the documented examples.

## Check a real server

```
php contract_check.php --base=https://qmgateway.com --live=qm_live_... --test=qm_test_...
```

Exit code 0 means the live API still matches the contract. Use keys of a test organisation: the runner sends real (queued) messages with the live key.

## What "done" means for an SDK

1. Models cover every field in the schemas and tolerate missing optional fields and extra fields.
2. Every case in `cases.json` works, and every error case raises the typed error with the right `code`.
3. The verifier gives the expected answer for every case in `webhook-cases.json`.
4. The retry and pagination scenarios behave as described.

Security: see [SECURITY.md](SECURITY.md). Licence: MIT.