# FLIZpay for JTL-Shop 5

Accept payments with FLIZpay directly in JTL-Shop, with no merchant fees and
discounts/cashback for your customers.

- **Requirements:** JTL-Shop 5.3.0 or newer, PHP 8.1 or newer, and outgoing HTTPS
  connections to `api.flizpay.de`
- **Currency:** EUR
- **Order flow:** The order is created before payment. The customer then completes
  payment on the FLIZpay website or in the FLIZpay app.

## Development

The plugin source files are located directly in the repository root. During the build,
they are packaged inside a `flizpay/` directory as required by JTL-Shop. This directory
name matches the `<PluginID>` in `info.xml`, from which JTL derives the
`Plugin\flizpay\` namespace.

| Path | Purpose |
|---|---|
| `info.xml` | Manifest, payment method, and admin menu (settings live in `lib/Admin/SettingsTab.php`) |
| `composer.json` | PHP target (8.1) for tooling/formatters; no runtime dependencies |
| `Bootstrap.php` | Hooks, routes, and plugin lifecycle |
| `paymentmethod/FlizPay.php` | Payment method and checkout flow |
| `lib/Api/` | FLIZpay HTTP client and API operations |
| `lib/Service/` | Payment processing, orders, discounts, cashback, and configuration |
| `lib/Controller/` | Webhook, return, and status endpoints |
| `Migrations/` | Database schema |
| `tests/` | Logic tests without shop or Composer dependencies |

```bash
php tests/run.php
./build.sh
```

`./build.sh` validates the PHP syntax, runs the tests, compiles the translation
catalogs, and creates `dist/flizpay-<version>.zip`.

## Installation

1. In the shop backend, go to **Plugins > Plugin Manager > Upload**, upload the plugin
   ZIP, and install it.
2. Go to **Plugins > FLIZpay > Settings**, enter the **API key** from the
   "Installation" section of your FLIZ company account, and save the settings.
3. The plugin automatically registers the webhook URL and retrieves the webhook key
   and current discount information.
4. FLIZpay immediately sends a test notification to the shop. Once it arrives, the
   connection status below the API key field switches to *connected*.
5. Assign FLIZpay to the required customer groups and shipping methods under
   **Payment methods**.

> **Important:** FLIZpay is only offered during checkout after the test notification
> has arrived. The shop must be publicly accessible, without password protection or IP
> restrictions, and must use a valid SSL certificate. This does not work on staging
> systems protected with HTTP Basic Authentication.

## Discounts and cashback

Discounts are configured exclusively in the FLIZ company account. The plugin retrieves
the current values when the settings are saved and keeps them updated through webhooks.
During checkout, it can display a title such as "FLIZpay - Up to X% discount".

When FLIZpay grants a discount for a payment, the plugin adds a discount item to the
order and corrects the order total after payment, before the order is transferred to
JTL-Wawi.

To make this possible, FLIZpay orders are always held back from JTL-Wawi until the
payment is confirmed. In the rare case where an order has already been picked up by
JTL-Wawi before the discount arrives, the plugin adds a note to the order so the
discount can be applied manually in JTL-Wawi.

## Payment confirmation by webhook

Payments are confirmed exclusively through signed FLIZpay webhooks. The customer return
page does not request the payment status from FLIZpay. It only reads the locally stored
status and briefly waits for the webhook.

The shop cannot reconstruct a lost webhook notification. FLIZpay must retry failed
deliveries. Until the webhook arrives, the order remains unpaid and is not transferred
to JTL-Wawi.

## Failed or cancelled payments

If a payment is cancelled or fails, the order remains **open** so the customer can retry
the payment from the order overview. The plugin does not automatically cancel unpaid
orders.

## Refunds

Refunds cannot be initiated through JTL-Shop and must be processed directly through
FLIZpay.

## Troubleshooting

- The **Settings** tab shows the connection state (below the API key field) and all
  open payments. The payment list is read-only; payment states are changed only by
  webhooks.
- **Log file:** The plugin writes warnings and errors to `jtllogs/flizpay.log` in the
  shop root (JTL's log directory; blocked from the web by JTL's `.htaccess` — nginx
  setups must deny `jtllogs/` themselves, as JTL's own `phperror.log` lives there too).
  Notice/error entries also appear in the payment-method log (**Payment methods >
  FLIZpay > Log**, linked from the Settings tab) with order and transaction IDs.
- **Debug logging:** Enable it under **Settings > Payment Options** to additionally record
  every API call, webhook and payment step in `flizpay.log`. The log never contains API
  keys, signatures, request/response bodies or customer data. The file is not rotated —
  disable debug logging when you are done and delete the file if it grows large.
- **After changing the domain or shop URL:** Save the plugin settings once — the
  plugin detects the changed webhook URL and re-registers it at FLIZpay.
