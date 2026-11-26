# Security policy

## Reporting a vulnerability

Please do **not** open a public issue. Either email **qmgateway@gmail.com**, or use "Report a vulnerability" on the Security tab of this repository. Include what you found, steps to reproduce, and what you think an attacker could do with it.

We acknowledge reports within 3 working days, give you an update at least every 10 working days, and credit you when the fix is released unless you prefer not to be named.

## What is in scope

This repository holds test data and two small developer scripts (`contract_mock.php`, `contract_check.php`). They only run on localhost or against a server you name, and they ship with no secrets. Report anything that could make them leak a key, run on a public web server, or give the SDKs a wrong test result (for example a webhook case that accepts a forged signature).

## How we protect it

- Changes go through pull requests that need a review from `@qmgateway-sms`.
- The CI workflow uses GitHub Actions pinned to full commit hashes, and Dependabot proposes updates.
- The files are generated from the QMGateway API documentation; they are never edited by hand.