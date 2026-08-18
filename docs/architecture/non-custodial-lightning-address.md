# Non-custodial Lightning address payments

How the plugin takes a payment when the merchant has configured a Lightning
address instead of a Blink API key.

## Why this mode is different

The custodial mode has an authenticated account behind it. The plugin asks the
Blink API to create an invoice, sends the customer to `pay.blink.sv`, and the
Blink dashboard calls back when the payment settles. The shop is told about the
payment by a party it authenticated to.

Non-custodial mode has none of that. The merchant supplies an address such as
`shop@blink.sv`, and the plugin speaks LNURL to whatever server answers for that
domain. There is no API key, no account, and — importantly — **no webhook**. The
only way to learn that a payment happened is to ask.

Everything below follows from that one fact.

## The flow

```
checkout
   │
   ├─ 1. convert the order total to satoshis        SatsRateProvider
   │
   ├─ 2. GET https://<domain>/.well-known/lnurlp/<name>      LUD-16 / LUD-06
   │        → callback URL, min/max sendable, comment limit, metadata
   │
   ├─ 3. GET <callback>?amount=<msat>&comment=GW-<order>     LUD-06 / LUD-12
   │        → BOLT11 invoice + verify URL
   │
   ├─ 4. decode and check the invoice                InvoiceValidator
   │        amount · payment hash · description binding · expiry · network
   │
   ├─ 5. store it on the order                       InvoiceRepository
   │
   └─ 6. schedule the first settlement check         SettlementScheduler
            │
            ▼
        order-pay page: QR code + polling
            │
            ├─ browser asks the shop for the last observed status
            └─ scheduler asks the verify URL, on an escalating curve

        GET <verify URL>                                     LUD-21
             → {"settled": true, "preimage": "…"}
```

Steps 2, 3 and the verify request all go through the same URL policy, because
every URL involved after step 2 is chosen by a third party.

## Settlement

Settlement is the part that had to change most, so it is worth being explicit
about the rules.

**A browser is not required.** An Action Scheduler job per pending order checks
the verify URL on an escalating curve — 20s, 45s, 90s, 3m, 5m, 8m, and so on,
each jittered by ±25%, clamped to the invoice's expiry plus a grace period. A
Lightning payment normally settles within seconds, while the tab is still open,
so the browser catches the common case and the scheduler exists for the customer
who paid on a phone and closed the laptop.

**Uncertainty never expires an order.** A timeout, a 5xx, a malformed body or a
rejected URL says nothing about whether the customer paid, so all of them yield
`PENDING`. An order is expired only when the window has passed _and_ the verify
endpoint is answering, or when it answers a definitive "no such invoice" after
expiry. After eight consecutive failures the plugin stops checking, leaves the
order pending and logs why. A merchant finding an order that needs a human is
recoverable; a customer whose paid order was cancelled is not.

**WooCommerce's stock timer defers to a payable invoice.** `wc_cancel_unpaid_orders()`
cancels pending orders once `woocommerce_hold_stock_minutes` has passed -- 60 by
default, against invoices that may be valid for 3600 seconds -- and that
cancellation used to take the order's scheduled checks with it. The plugin now
answers `woocommerce_cancel_unpaid_order` with `false` while a non-custodial
invoice is unresolved and inside its window. The reprieve releases itself: once
the invoice is terminal or past `expiresAt + EXPIRY_GRACE_SECONDS`, the timer
cancels the order as it normally would. A cancellation that reaches a live
invoice after that is a shop manager's decision, and it does stop the checks.

**The give-up budget belongs to the background job.** Only the scheduler's own
checks count towards it. A customer refreshing the pay page says nothing about
how much work the background job has done, and while both spent the same
counters a watched pay page exhausted them mid-invoice and killed background
settlement outright. What the pay page may still do is clear the consecutive
failure count when the endpoint answers it, since that is direct proof the
endpoint is reachable and can only delay giving up. Frequency of polling is
bounded by `PollBudget` and the status cache, never by these counters.

**One request at a time per order.** A lock whose lifetime exceeds the HTTP
timeout means many browser tabs and a scheduler tick collapse into a single
outbound request. The browser reads a cached observation (20 seconds) and only
triggers a live check when that is stale.

**Settlement checks the money.** The preimage must hash to the invoice's payment
hash. The order total and currency must still match what the invoice was created
for; if they do not, the order is held `on-hold` for review rather than
completed.

### Settlement states

| State     | Meaning                                                             | Terminal |
| --------- | ------------------------------------------------------------------- | -------- |
| `PENDING` | not paid yet, or nothing conclusive was learned                     | no       |
| `PAID`    | settled, preimage verified, totals unchanged                        | yes      |
| `EXPIRED` | window passed with the endpoint answering, or definitively gone     | yes      |
| `REVIEW`  | paid, but the order changed after the invoice was created           | yes      |
| `UNKNOWN` | budget spent, lock held, or no invoice — callers must not act on it | no       |

## What is trusted

Only one thing: the Lightning address the merchant typed into the settings
screen. Everything the server at that address returns — the callback URL, the
verify URL, the invoice — is treated as attacker-controlled. See
[the SSRF policy](../security/ssrf-policy.md) for the URL rules and
[the seams document](seams.md) for how that is enforced.

The invoice is not signature-checked, deliberately. It arrives over TLS from a
host inside the merchant's own domain, so a valid signature would only prove
that some node signed it. What matters is _binding_: that the invoice asks for
the amount that was requested, matches the payment hash settlement is checked
against, and is bound to the metadata the address advertised. Those are checked,
and the preimage proves payment at settlement.

## Order meta

Everything except the first three keys is `_`-prefixed, which WordPress treats as
protected meta and hides from the order editor's custom-fields box. That matters:
`_blink_verify_url` is fetched over HTTP and decides settlement, so a shop
manager must not be able to repoint it at a server that always answers "settled".

| Key                     | Type   | Written by        | Read by                            | Purpose                                                                                     |
| ----------------------- | ------ | ----------------- | ---------------------------------- | ------------------------------------------------------------------------------------------- |
| `blink_id`              | string | InvoiceRepository | webhook lookup, settlement         | Payment hash. Keeps its unprefixed name because the custodial webhook queries orders by it. |
| `blink_payment_request` | string | InvoiceRepository | pay page                           | The BOLT11 invoice                                                                          |
| `blink_redirect`        | string | custodial path    | —                                  | Custodial off-site pay URL                                                                  |
| `_blink_account_type`   | string | InvoiceRepository | pay page, poll endpoint, scheduler | Marks the order as non-custodial                                                            |
| `_blink_verify_url`     | string | InvoiceRepository | settlement                         | LUD-21 endpoint                                                                             |
| `_blink_ln_address`     | string | InvoiceRepository | settlement                         | The address the invoice was created against                                                 |
| `_blink_amount_msat`    | int    | InvoiceRepository | —                                  | Exact amount requested                                                                      |
| `_blink_satoshis`       | int    | InvoiceRepository | pay page                           | Amount shown to the customer                                                                |
| `_blink_created_at`     | int    | InvoiceRepository | scheduler                          | Invoice creation time                                                                       |
| `_blink_expires_at`     | int    | InvoiceRepository | settlement, pay page               | Decoded from the invoice, not assumed                                                       |
| `_blink_order_total`    | string | InvoiceRepository | settlement                         | Total at creation, for the change check                                                     |
| `_blink_order_currency` | string | InvoiceRepository | settlement                         | Currency at creation                                                                        |
| `_blink_status`         | string | SettlementService | poll endpoint                      | Last observed status                                                                        |
| `_blink_status_at`      | int    | SettlementService | poll endpoint                      | When it was observed                                                                        |
| `_blink_attempts`       | int    | SettlementService | background give-up                 | Background checks made; foreground polls never count                                        |
| `_blink_errors`         | int    | SettlementService | background give-up                 | Consecutive background failures; any answer clears it                                       |
| `_blink_settled_at`     | int    | SettlementService | idempotency latch                  | When settlement was first recorded                                                          |
| `_blink_preimage`       | string | SettlementService | —                                  | Payment proof                                                                               |
| `_blink_terminal`       | string | SettlementService | everything                         | Final state, short-circuits further work                                                    |

`_blink_ln_address` is the fix for a specific bug: settlement used to read the
shop's _current_ address, so changing it in the settings screen stranded every
order already in flight.

## Filters

| Filter                                     | Purpose                                                                                                           |
| ------------------------------------------ | ----------------------------------------------------------------------------------------------------------------- |
| `blink_service_*`                          | Replace any service in the graph (`blink_service_http`, `blink_service_clock`, …)                                 |
| `blink_bolt11_require_description_binding` | Relax the metadata binding check for a server that does not echo metadata. On by default; disabling it is logged. |
| `blink_client_ip`                          | Supply the real client address on a proxied site. Only `REMOTE_ADDR` is trusted by default.                       |
