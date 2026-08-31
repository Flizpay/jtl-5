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
| `info.xml` | Manifest, payment method, settings, and admin menu |
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
   connection is shown as *connected* on the **Status** tab.
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

For this to work, **Hold orders from JTL-Wawi until payment** must remain enabled. This
setting is enabled by default. If it is disabled, the plugin cannot apply the discount
to the order automatically. Instead, it adds a note to the order so the discount can be
applied manually in JTL-Wawi.

## Payment confirmation by webhook

Payments are confirmed exclusively through signed FLIZpay webhooks. The customer return
page does not request the payment status from FLIZpay. It only reads the locally stored
status and briefly waits for the webhook.

The shop cannot reconstruct a lost webhook notification. FLIZpay must retry failed
deliveries. Until the webhook arrives, the order remains unpaid and, when the JTL-Wawi
hold is enabled, is not transferred to JTL-Wawi.

## Failed or cancelled payments

If a payment is cancelled or fails, the order remains **open** so the customer can retry
the payment from the order overview. The plugin does not automatically cancel unpaid
orders.

## Refunds

Refunds cannot be initiated through JTL-Shop and must be processed directly through
FLIZpay.

## Troubleshooting

- The **Status** tab shows the connection state, registered webhook URL, time of the
  latest notification, discount information, and all open payments. The payment list is
  read-only; payment states are changed only by webhooks.
- **Payment log:** Open **System > Log** in the backend or inspect the payment-method
  log. FLIZpay events are recorded with the corresponding order and transaction IDs.
- **After changing the domain or shop URL:** Reconnect once from the **Status** tab so
  FLIZpay receives the new webhook URL.
