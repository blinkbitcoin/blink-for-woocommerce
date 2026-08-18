# 4. Settle in the background with Action Scheduler

Accepted, 2026-08.

## Context

Non-custodial payments have no webhook. Settlement previously happened only
while the customer's pay page was open, so a buyer who scanned the QR code with
a phone and closed the laptop paid the merchant and then watched WooCommerce
cancel the order when hold-stock expired.

## Decision

Schedule a settlement check per pending order with Action Scheduler, on an
escalating curve (20s, 45s, 90s, 3m, 5m, 8m …), each jittered by ±25% and
clamped to the invoice's expiry plus a grace period.

## Rationale

WP-Cron only advances when someone visits the site, which is precisely the
situation background settlement exists to survive. Action Scheduler ships
inside WooCommerce, which is already a hard requirement, so it adds nothing to
install.

The curve escalates rather than polling at a fixed rate because a Lightning
payment settles in seconds, while the tab is almost always still open. The
browser catches the ordinary case for free; the scheduler pays only for the
abandoned one.

Jitter keeps many shops, or many orders on one shop, from hitting a provider in
lockstep.

## Consequences

- Settlement no longer depends on a browser.
- If Action Scheduler is somehow absent, `NullScheduler` degrades to
  browser-driven settlement rather than fatalling during checkout.
- Scheduling and execution are asserted separately: unit tests prove the right
  work was requested at the right time, integration tests prove the queue runs
  it.
