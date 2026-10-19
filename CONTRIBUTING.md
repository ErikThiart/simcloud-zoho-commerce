# Contributing

Use focused issues and pull requests. Match the procedural PHP and dependency-free JavaScript style. Keep financial state changes explicit and preserve merchant edits. Do not add automated purchase retries.

Run `npm test`, PHP lint, `python3 scripts/check.py`, and `npm run build`. Explain whether a test used simulated responses, a real Zoho test store, or a funded provider purchase. Never claim live delivery based on a mocked response.

Never commit credentials, local config, databases, customer information or redeemable codes. Use synthetic fixtures. An API change must include an official source and an actionable regression test.

Enable the included pre-push check:

    git config core.hooksPath .githooks

It runs the public-source and package checks before a push. CI fetches the complete history and repeats the check. Default unbound packages must pass; keep account-bound deployment ZIPs outside public release uploads.

To validate the widget with Zoho's official toolkit, install a pinned `zoho-extension-toolkit` version from npm outside the runtime package and run `zet validate` in this repository. Local Python packaging uses an explicit runtime allowlist so connector files never enter the widget ZIP.
