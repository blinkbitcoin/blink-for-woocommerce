# 4. Settle in the background with Action Scheduler

Accepted, 2026-08.

## Context

Non-custodial payments have no webhook. Settlement previously happened only
while the customer's pay page was open, so a buyer who scanned the QR code with
a phone and closed the laptop paid the merchant and then watched WooCommerce
cancel the order when hold-stock expired.

## Decision

Schedule a settlement check per pending order with Action Scheduler after 20
and 45 seconds, then at a 45-second interval, each jittered by ±25% and clamped
to the invoice's expiry plus a grace period. Arm each successor before making
the current request so an unexpected failure cannot break the chain.

Keep trigger selection separate from settlement behavior. `SettlementMode` is
a string-backed enum with three supported values:

| Enum case     | Serialized value | Browser may verify | Worker runs |
| ------------- | ---------------- | ------------------ | ----------- |
| `BrowserOnly` | `browser_only`   | yes                | no          |
| `Hybrid`      | `hybrid`         | yes                | yes         |
| `WorkerOnly`  | `worker_only`    | no, cache-only     | yes         |

`Hybrid` is the production default. A replacement
`SettlementModeProviderInterface` may be injected through the existing service
filter for a staged rollout or rollback without branching the settlement
algorithm.

Domain and integration tokens have one owner. Settlement results come from the
backed `SettlementStatus` enum; the scheduler hook and group come from
`SettlementScheduler`; order-meta names come from `InvoiceRepository`; and
Action Scheduler action states use `ActionScheduler_Store`'s constants. A
documented external token for which the dependency exposes no constant, such as
Action Scheduler's `ids` return format, is named once inside its adapter.

## Rationale

Action Scheduler ships inside WooCommerce, which is already a hard requirement,
so it adds nothing to install. Its default runner is initiated by WP-Cron; an
idle store therefore needs its host's regular cron runner if settlement must be
observed within a bounded time.

The bounded cadence keeps a healthy one-minute queue inside the two-minute
settlement target. Request budgets and the per-order lock still cap concurrency.

Jitter keeps many shops, or many orders on one shop, from hitting a provider in
lockstep.

A backed mode enum prevents configuration and test fixtures from growing
independent spellings of the same modes. Keeping external constants in the
adapter that owns the integration also avoids leaking queue vocabulary into the
settlement domain.

## Consequences

- Settlement no longer depends on a browser.
- `Hybrid` remains the default and preserves the browser-triggered fallback;
  `BrowserOnly` and `WorkerOnly` are injectable orchestration alternatives.
- The serialized mode values are an integration contract. Add or rename a mode
  in `SettlementMode` rather than introducing another string comparison.
- Ordinary response field names and user-facing copy remain strings; constants
  are reserved for domain choices, repeated identifiers and external API
  tokens where they prevent drift.
- In the default `Hybrid` mode, if Action Scheduler is somehow absent,
  `NullScheduler` leaves the browser fallback available rather than fatalling
  during checkout.
- Scheduling and execution are asserted separately: unit tests prove the right
  work was requested at the right time, integration tests prove the queue runs
  it.
