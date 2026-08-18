# Blink for WooCommerce

Accept Bitcoin over the Lightning Network in WooCommerce, using
[Blink](https://www.blink.sv/).

> The WordPress.org plugin listing lives in `readme.txt`. Running
> `npm run readme` renders a preview to `docs/wordpress-org/readme.md`.
> **Do not edit that file** — and note this `README.md` is hand-maintained, so
> do not regenerate it from `readme.txt` either.

## Two ways to take payment

**Custodial.** Connect a Blink account with an API key. The plugin creates
invoices through the Blink API, sends the customer to `pay.blink.sv`, and the
Blink dashboard notifies your shop when a payment settles.

**Non-custodial.** Enter a Lightning address such as `shop@blink.sv`. No API
key, no account connection. The plugin speaks LNURL to whatever server backs
that address, shows the invoice as a QR code on your own site, and checks for
settlement itself — there is no webhook in this mode.

Merchants choosing between them should read
[the setup guide](docs/merchant/non-custodial-setup.md), which is honest about
the trade-offs.

## Quick start

```bash
git clone https://github.com/blinkbitcoin/blink-for-woocommerce.git
cd blink-for-woocommerce
composer install
docker-compose up          # WordPress + WooCommerce on http://localhost:8080
```

Then: WooCommerce → Settings → Payments → Blink.

## Documentation

| | |
|---|---|
| [Contributing](CONTRIBUTING.md) | Setup, running the suites, what review asks about |
| [Testing](docs/testing.md) | The three tiers, the fakes, the coverage gate |
| [Non-custodial architecture](docs/architecture/non-custodial-lightning-address.md) | The LNURL flow, settlement, order meta |
| [Seams](docs/architecture/seams.md) | The injected interfaces and why each exists |
| [Outbound request policy](docs/security/ssrf-policy.md) | Trust boundaries and URL rules |
| [Merchant setup](docs/merchant/non-custodial-setup.md) | Lightning address mode, end to end |
| [Security policy](SECURITY.md) | Reporting a vulnerability |

## Requirements

PHP 8.1+, WordPress 6.0+, WooCommerce 9.0+.

## Licence

MIT. See [license.txt](license.txt).
