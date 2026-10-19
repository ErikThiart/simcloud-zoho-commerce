# Security reporting

Do not post security vulnerabilities, tokens, phone numbers, meter numbers or voucher/electricity codes in public issues.

Use the repository's GitHub **Security → Report a vulnerability** facility when enabled. Otherwise contact the maintainer through the SIMcloud support channel linked from [SIMcloud](https://simcloud.co.za/) and request a private reporting route before transmitting sensitive evidence.

This is a development alpha, not an independently audited payment or fulfilment system. Keep live fulfilment disabled until the acceptance checklist passes.

Credential rotation must include SIMcloud, Zoho OAuth and the bridge connection as applicable. Back up and protect the encryption key separately from the ledger. Losing that key makes encrypted recipients and delivery codes unrecoverable.
