# Taking payments with a Lightning address

This mode lets you accept Bitcoin without connecting a Blink account to your
shop. You enter a Lightning address; payments go straight to the wallet behind
it.

## Choosing between the two modes

|                                    | Custodial             | Non-custodial                             |
| ---------------------------------- | --------------------- | ----------------------------------------- |
| What you enter                     | A Blink API key       | A Lightning address, e.g. `shop@blink.sv` |
| Where the customer pays            | On `pay.blink.sv`     | On your own site                          |
| How your shop learns about payment | Blink calls your site | Your site checks, repeatedly              |
| Who holds the funds                | Your Blink account    | Whoever runs the address                  |
| Stablesats (USD-pegged)            | Yes                   | No — Bitcoin only                         |

The honest summary: non-custodial means your shop is not connected to an account
it can be locked out of, but it also means your shop has no authenticated
relationship with the payment provider. Everything it knows about a payment
comes from asking the address's server, over the public internet.

## Setting it up

1. **Get a Lightning address.** Any provider works. In the Blink app it is shown
   in your wallet as `something@blink.sv`.
2. **WooCommerce → Settings → Payments → Blink.**
3. Set **Account Type** to _Non-custodial (lightning address)_.
4. Enter the address and save.

The settings screen checks the address when you save and tells you if it cannot
be reached. That check is cached for a few minutes, so if you fix a typo and
save again the result may take a moment to catch up.

You do **not** need an API key, and you do **not** need to configure a webhook
in the Blink dashboard.

## What the customer sees

1. They choose Blink at checkout.
2. They land on a page on your shop showing a QR code, the amount in satoshis,
   and a copyable invoice.
3. They pay from any Lightning wallet.
4. The page updates and sends them to your order-received page.

## What happens if they close the page

The order still completes. Your shop keeps checking in the background for as
long as the invoice is valid — roughly an hour — so a customer who scans the QR
code with their phone and closes the laptop is fine.

This is worth stating plainly because it is the one thing most likely to worry
you: **the customer's browser is not what confirms the payment.**

## Limits

The address's provider sets a minimum and maximum it will accept. If an order
total falls outside that range, checkout will refuse rather than produce an
invoice nobody can pay. If you sell items priced far apart, check your
provider's limits.

Invoices are valid for up to an hour. If one expires unpaid, the order is
cancelled and the customer can order again.

## Troubleshooting

**"Could not verify this Blink lightning address" when saving.**
Check the spelling. Then check the address works elsewhere — try sending it a
small amount from a wallet. The plugin also refuses addresses on domains it
cannot reach over HTTPS, and addresses pointing at a private or internal
address.

**Checkout says the invoice could not be created.**
Usually the order total is outside the provider's accepted range, or the
provider returned an error. Turn on **Debug log** in the Blink settings and look
at WooCommerce → Status → Logs; the entry names the exact reason.

**A customer says they paid, but the order is still pending.**
Give it a minute — background checks are spaced out. If it stays pending, look
at the log. The most common cause is that the address's verify endpoint is
temporarily unreachable. The plugin deliberately leaves such orders pending
rather than cancelling them, because it cannot tell an unreachable server from
an unpaid invoice. Once the endpoint responds again, the order settles.

If the plugin gave up after repeated failures, it says so in an order note.

**An order is On hold saying the total changed.**
The order was edited between the invoice being created and the payment
arriving, so the customer paid a different amount from the current total. The
plugin will not complete it automatically. Check what was actually paid before
you fulfil it.

**Orders are being cancelled and customers say they paid.**
This should not happen — an order is only expired when the plugin has
successfully reached the verify endpoint and been told the invoice is unpaid.
If you see it, please [report it](../../SECURITY.md).

## Data sent to third parties

In this mode your shop makes requests to **the domain of the Lightning address
you configure**. That is not necessarily Blink: if you enter
`you@example.com`, your shop talks to `example.com`. Order totals and an order
reference are sent as part of creating an invoice.

Your shop also uses Blink's public currency-conversion endpoint to convert the
order total into satoshis. That request needs no account and identifies no
customer.
