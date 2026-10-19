# Testing and release acceptance

This public source release is a development alpha. Automated tests use synthetic fixtures and simulated provider responses. They do not authorize a merchant account or certify Marketplace approval, real checkout, gateway behaviour or live provider delivery.

## Repeatable local checks

```sh
npm test
npm run check
npm run build
python3 scripts/test_packages.py
```

- Node tests exercise SDK response envelopes, safe error handling, organization context, credential-free widget requests, filtering and bounded imports.
- PHP tests exercise catalogue mapping, validation, encryption, import recovery, merchant-edit preservation, payment gates, provider identity and order reconciliation.
- Eight concurrent PHP processes contend for one SQLite ledger; the test requires exactly one simulated purchase attempt.
- HTTP tests run the actual PHP front controller locally to check authorization, store isolation, methods, invalid JSON, size limits and disabled live fulfilment.
- Package tests extract the exact runtime ZIPs and run the source harnesses against those extracted files. They also verify both widget manifests and the SDK dependency.
- Documentation checks validate local links and 23 bundled logo checksums. Publication checks reject private artifacts, account bindings, token/private-key patterns and non-public website routes from source and default packages.

CI runs the source and package checks on PHP 8.2, 8.4 and 8.5. Inspect the [current Actions results](https://github.com/ErikThiart/simcloud-zoho-commerce/actions) for the exact commit being evaluated.

## Merchant acceptance checklist

Keep records privately, using synthetic data where possible. Do not commit credentials, store identifiers, operational transcripts or real account screenshots as proof.

- [ ] Confirm supported Zoho region, plan and minimum OAuth scopes.
- [ ] Install and verify both sidebar locations and installation settings.
- [ ] Authorize the secure connection; read balance/catalogues through native API Configurations.
- [ ] Verify data/VAS catalogue mappings, all pagination and default logos.
- [ ] Create and repeat imports for every supported service; preserve merchant edits and removed products.
- [ ] Verify correct layouts, required recipient/meter validation, leading-zero meters and per-line order propagation.
- [ ] Complete service and mixed-cart checkout without shipping or inappropriate quantity changes.
- [ ] Verify actual gateway paid/refunded/cancelled/test/offline response fields.
- [ ] Exercise duplicate workflow delivery and an interrupted worker.
- [ ] Configure private cron, logs, locks, backups, sender and bounce handling.
- [ ] Obtain explicit owner authorization before each funded recipient/product/amount/reference test.
- [ ] Verify provider acceptance and delivery using saved IDs for airtime, data, VAS and electricity.
- [ ] Verify revocation, uninstall and retention.
- [ ] Complete Zoho review and Marketplace approval before advertising a marketplace release.

If field visibility, validation, native API transport or payment evidence fails, keep live fulfilment disabled. A successful source test or admin save is not a substitute for storefront and provider acceptance.
