# FLIZpay for JTL-Shop 5 — development repository

The installable plugin lives in [`flizpay/`](flizpay/) — that folder *is* the plugin
(its name must stay identical to the `<PluginID>` in `info.xml`, since JTL derives the
PSR-4 namespace root `Plugin\flizpay\` from it). The merchant-facing documentation is
[`flizpay/README.md`](flizpay/README.md).

## Layout

| Path | Purpose |
|---|---|
| `flizpay/info.xml` | Manifest: payment method, settings, admin menu |
| `flizpay/Bootstrap.php` | Hook/route registration and plugin lifecycle |
| `flizpay/paymentmethod/FlizPay.php` | The payment method (checkout leg) |
| `flizpay/lib/Api/` | FLIZpay HTTP client and typed API operations |
| `flizpay/lib/Service/` | Settlement machine, order/discount/cashback/config services |
| `flizpay/lib/Controller/` | Webhook, customer-return and status-polling endpoints |
| `flizpay/Migrations/` | Database schema |
| `tests/` | Logic tests (no shop or composer needed) |

## Build

```bash
./build.sh          # lints, tests, writes dist/flizpay-<version>.zip
```

Upload the resulting ZIP in the shop backend under *Plugins → Plugin-Verwaltung → Upload*.

## Tests

```bash
php tests/run.php
```

Covers the webhook settlement state machine (validation, idempotency, attempt/retry
model, concurrency claims) and webhook signature verification. These are pure logic tests with
in-memory doubles, so they need neither a shop installation nor composer.

## Local development against a real shop

FLIZpay has **no sandbox** — testing requires a real API key and a publicly reachable
shop, because FLIZpay registers the webhook URL server-side and calls back into it.

1. Run JTL-Shop 5.3+ locally (PHP 8.1+, MySQL 8).
2. Expose it under a **stable** public hostname (a named `cloudflared` tunnel or a
   fixed-domain ngrok). Install the shop *under that hostname* — JTL pins the shop URL
   in its configuration and the webhook URL is derived from `Shop::getURL()`.
3. Enter the API key in the plugin settings; the handshake registers
   `https://<host>/flizpay/webhook` and FLIZpay confirms it with a test notification.
4. Place test orders with a small amount. Real money moves — there is no refund API, so
   reversals go through FLIZpay support.

## Open questions for the FLIZpay backend team

1. **Webhook signature basis** — the WooCommerce plugin verifies the HMAC over
   `json_encode(json_decode($body), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)`
   rather than the raw body. This plugin accepts both (raw first, re-encoded as
   fallback); confirm which one the backend actually signs so the fallback can be
   dropped.
2. **`source` value** — is a platform identifier wanted for JTL, or does `"plugin"`
   stay correct?
3. **One webhook URL per business** — what happens for a merchant running both
   WooCommerce and JTL-Shop? Does registering the second URL overwrite the first?
4. **`Idempotency-Key` prefix** — this plugin sends `jtl-<sha256>`; confirm that is fine.
5. **`X-FLIZpay-Plugin-Version`** — should the JTL plugin report a separate version
   series so it is distinguishable from the WooCommerce plugin?
6. **Minimum/maximum transaction amount**, if any.
7. **Webhook redelivery** — does FLIZpay retry on 5xx/timeout? The plugin answers 200
   for permanently unprocessable events (with `{"accepted": false, "reason": …}`) and
   5xx only for transient failures, which assumes retries happen.
8. **Re-triggering the test webhook** on demand, to support the "reconnect" button.
9. **When is the `{test}` webhook dispatched?** The handshake registers the webhook URL
   *before* it fetches the webhook key (same order as the WooCommerce plugin). If the
   test notification is triggered by URL registration rather than by key generation, it
   can arrive while the shop still holds the previous key and would be rejected as
   unsigned. The "Verbindung neu aufbauen" button recovers from that, but the ordering
   should be confirmed.
