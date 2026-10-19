# What belongs in this public repository

The repository contains reusable plugin code, a standalone merchant connector, synthetic test fixtures, generic installation instructions and demonstration screenshots. The public SIMcloud API contract and public product-logo provenance are appropriate references.

Keep the following outside the repository, Git history, releases, CI artifacts, screenshots and issues:

- API tokens, OAuth secrets, refresh tokens, encryption keys and connection credentials.
- Real organization/extension IDs, generated connection namespaces and account-specific widget bindings.
- Customer records, recipient/meter values, order exports, redeemable codes and database files.
- Production website source, session/account adapters, deployment tooling, hostnames and private filesystem layouts.
- Raw logs, support transcripts, incident reports and real account screenshots.

Use private environment configuration and the merchant's secure credential system. Use synthetic examples and reserved example domains in public documentation. Public default packages are unbound; account-bound builds belong only to the intended merchant deployment.

Run `npm run check` before committing. `scripts/public_check.py` checks the source tree, reachable Git blobs and default build ZIPs for prohibited artifacts and common credential patterns. CI repeats the same checks. Pattern checks cannot recognize every possible private fact, so a human must review all new screenshots and operational content before publication.

Runtime ZIPs use the explicit list in `config/package-files.json`. Adding a source file never adds it to a runtime package automatically. The build never reads the merchant's private configuration.

Enable the included pre-push check:

    git config core.hooksPath .githooks

It runs before Git transmits a push. CI checks the complete fetched history rather than only a shallow copy of the latest commit.

For security concerns, use [private reporting](../SECURITY.md), not a public issue containing sensitive evidence.
