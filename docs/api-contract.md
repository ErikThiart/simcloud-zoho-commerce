# SIMcloud API mapping

The source of truth is the [SIMcloud API master index](https://simcloud.co.za/api/index.html), its linked service documentation and the [OpenAPI contract](https://simcloud.co.za/api/openapi.yaml). Reviewed against the live public documentation on 19 October 2026.

The API already exists. This project integrates with it; it does not replace the SIMcloud API or require a separate SIMcloud API to be built. The PHP connector implements Zoho-specific payment verification, product mapping and a durable purchase ledger around those existing endpoints.

All provider requests use `Authorization: Bearer <merchant token>` on the server. Purchase bodies use JSON. The browser never receives that token.

| Purpose | Request | Connector handling |
| --- | --- | --- |
| Wallet balance | `GET /api/balance.php` | Setup reads the last-known balance; it does not reserve funds. |
| Data catalogue | `GET /api/data.php?products=1` | Map each unique network/amount bundle, use API amount as initial retail price, attach its bundled network logo. |
| VAS catalogue | `GET /api/vas.php` | Map each product/available denomination and its matching bundled brand logo. |
| Network lookup | `GET /api/network.php?msisdn=…` | Verify recipient network before airtime/data submission. |
| Airtime purchase | `POST /api/airtime.php` | `msisdn`, `network`, `amount`, `reference`. R2–R999. |
| Data purchase | `POST /api/data.php` | `msisdn`, `network`, `amount`, `reference`. Use a current catalogue network/amount pair. Catalogue ID is not a replacement purchase parameter. |
| Airtime/data status | `GET /api/{airtime,data}.php?request_id=…` | Persist the acknowledgement ID, then query that ID; require matching purchase identity before accepting delivery. |
| VAS purchase | `POST /api/vas.php` | `product_id`, `amount`, `reference`. Require an available denomination. |
| VAS status | `GET /api/vas.php?order_id=…` | `pending`, `success` or `failed`; successful delivery must include nonempty voucher codes. |
| Meter support | `GET /api/electricity.php?meter_number=…` | Require matching meter and `supported: true`. A supplier error with `supported: null` does not authorize purchase. |
| Electricity purchase | `POST /api/electricity.php` | `meter_number`, whole-rand `amount`, `client_reference`, `recipient`, `send_sms: false`. R55–R1000. |
| Electricity status | `GET /api/electricity.php?order_id=…` | Preserve the provider's timestamped reference; retain all returned token entries, including key-change tokens. |

For electricity, `send_sms: false` avoids a second provider notification because this connector handles voucher/token email. The configured gross amount includes the SIMcloud electricity fee; the connector does not claim the entire gross amount becomes electricity units.

The index also documents registration, SMS and transaction history. Those services are outside this extension's current storefront scope and are not silently called during installation. Merchants use their own SIMcloud accounts.

The documented five-minute airtime/data duplicate check is not a durable idempotency guarantee. A timed-out purchase can have been accepted; the ledger holds it for reconciliation and never automatically repeats the POST.

The public docs and machine-readable contract were fetched without an account token. No authenticated catalogue request or paid provider purchase is implied by this review. Connected-store/provider testing remains in [verification](verification.md).
