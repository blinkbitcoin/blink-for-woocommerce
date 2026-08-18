# Future work

Things deliberately left undone, and why. Each entry says what the current
behaviour is, what it should become, and what makes it worth doing — so that a
future reader can judge the trade rather than re-derive it.

Nothing here is a known way to lose a customer's payment. Where something could
affect money, that is called out explicitly.

---

## 1. Unify the custodial and non-custodial payment paths

**Now.** Two flows share a gateway class and little else. Custodial goes through
`BlinkApiHelper` and an authenticated GraphQL API; non-custodial goes through
`InvoiceFactory`, `LnurlClient` and `SettlementService`. `BlinkLnGateway`
branches between them, and `OrderStatusApplier` is the only piece both use.

**Should be.** A `PaymentBackend` interface with `createInvoice()` and
`checkStatus()`, implemented once per mode. The gateway would select a backend
and stop knowing which mode it is in; settlement, scheduling, budgets and the
pay page would work for either.

**Why it was not done now.** The refactor was deliberately scoped to the
non-custodial path. Reshaping the custodial flow at the same time would have
made the diff impossible to review against a feature branch that is already
large, and the custodial path has no test coverage of its own to refactor
against — only the characterization tests added here, which pin its externally
visible behaviour rather than its internals.

**What it needs first.** Custodial coverage. `BlinkApiClient` builds its own
Guzzle client (see §3), so custodial invoice creation cannot currently be
tested without the network. Fix that, cover the custodial flow, then unify.

**Payoff.** Non-custodial improvements that ought to apply to both currently do
not: background settlement, the request budgets, the single-flight lock, the
retry schedule, and the amount-change check are all non-custodial only.

---

## 2. Call `payment_complete()` on the custodial path

**Now.** Only the non-custodial path calls it, and only when the merchant has
left the paid state unmapped. The custodial path calls `update_status()`
directly, so `_date_paid` and `transaction_id` are never set and
`woocommerce_payment_complete` never fires. Shipping, accounting and
subscription plugins therefore never learn that a custodial order was paid.

**Should be.** Both paths use the same bookkeeping.

**Why it was not done now.** It changes how existing custodial orders
transition. WooCommerce picks `processing` or `completed` based on cart
contents, which can differ from the merchant's configured mapping, so a shop
could see different behaviour after an update. Verifying it needs a live Blink
account.

**Marker in code.** `OrderStatusApplier::completePaymentIfUnmapped()`.

---

## 3. Put the Blink API client behind the HTTP seam

**Now.** `BlinkApiClient` constructs `new \GuzzleHttp\Client()` internally.
Timeouts were added, but it cannot be substituted, so anything calling it
reaches the network.

**Should be.** It takes `HttpClientInterface`, like everything in the
non-custodial path.

**Consequences of not doing it.** Custodial invoice creation cannot be tested
without the network, which is what blocks §1. It also means the DNS pinning,
body-size cap and protocol restrictions applied to LNURL requests do not apply
to Blink API requests — a smaller concern, since that host is fixed and
first-party, but an inconsistency.

**Shape.** The client is POST/GraphQL-shaped where `HttpClientInterface` is
GET-only, so the interface needs a `post()` method before this can happen.

---

## 4. Verify custodial webhook authenticity

**Now.** `processWebhook()` accepts any POST to the WooCommerce API endpoint. It
does not trust the body: it re-queries the Blink API for the real status, which
is what keeps this from being exploitable for free orders. What an attacker can
do is make the shop perform an API lookup, and learn nothing.

**Should be.** An HMAC over the payload with a shared secret, checked before
anything else.

**Why it was not done now.** It needs Blink's dashboard to emit a signature, so
it is a coordinated change across two systems. It also changes behaviour for
every existing merchant on upgrade, which needs a migration path (accept
unsigned during a transition, then require).

**Risk of leaving it.** Low, given the re-query. Worth doing for defence in
depth and to remove the free lookup.

---

## 5. Reconsider `validInvoiceExists()` on an API error

**Now.** When the Blink API cannot be reached, custodial checkout reuses the
existing invoice. This was accidental — a null dereference that evaluated to
"not expired" — and the null is now guarded, but the behaviour was deliberately
preserved.

**Should be.** Return `false` and create a fresh invoice, so a customer is never
shown a QR code that may already be dead.

**Why it was not done now.** It is a behaviour change on the custodial path
that this branch is otherwise not touching, and it wants verification against a
real account.

**Marker in code.** `BlinkLnGateway::validInvoiceExists()`.

---

## 6. Extend the coverage gate to the rest of `src/`

**Now.** `coverage-gate.json` covers `src/Http`, `src/NonCustodial`,
`src/Orders` and `src/Support`. Not covered: `src/Helpers/BlinkApiClient.php`,
`BlinkApiHelper.php`, `OrderStates.php`, `Logger.php`, `src/Admin/`,
`src/Blocks/`.

**Should be.** All of `src/`.

**Why it was not done now.** Those files predate this work and have no tests.
Requiring 100% on them would have made this branch depend on covering unrelated
legacy code.

**How.** The list is a ratchet — entries may be added, never removed. Add one
file per pull request as it gets covered. §3 unblocks the largest of them.

---

## 7. Reduce the cost of the coverage job

**Now.** Branch coverage requires Xdebug in path-coverage mode. Over the
integration suite — which boots WordPress and WooCommerce for every test — that
turns a one-minute run into tens of minutes. It is one CI job and it is
correct, but it is slow enough to discourage running locally.

**Options, roughly in order of appeal.**

- Split the integration suite so only the tests that cover adapter classes run
  under path coverage; the behavioural tests contribute nothing the adapters
  suite does not already cover.
- Cache the static-analysis results between runs more aggressively
  (`cacheDirectory` is set; its effect on repeat runs has not been measured).
- Accept line-only coverage from the integration tier and gate branches solely
  on the unit tier, documenting that adapters are line-gated. This weakens the
  guarantee and should be the last resort.

**Why it matters.** A gate developers avoid running locally gets discovered in
CI, which is the slowest possible feedback.

---

## 8. Shrink the PHPStan baseline

**Now.** PHPStan runs at level 8 and passes. New code has zero findings; the
baseline holds 75 legacy ones, all in `BlinkLnGateway`, `BlinkApiClient`,
`BlinkApiHelper`, `GlobalSettings`, `BlinkLnGatewayBlocks`, `OrderStates` and
`Logger`.

**Should be.** Empty.

**Rule.** The baseline may shrink and never grow. It contains no entry for
`src/Http`, `src/NonCustodial`, `src/Orders`, `src/Support` or
`src/Services.php`, and that property is worth checking in review — it is what
stops new code being quietly excused.

**Not yet reviewed.** Nobody has read the 75 for real defects. Doing so is
worthwhile; anything found should be fixed rather than left frozen.

---

## 9. Scope the bundled Guzzle

See [ADR 6](architecture/adr/0006-guzzle-not-scoped.md). WordPress has no
dependency isolation, so two plugins bundling incompatible Guzzle versions can
break each other. The `HttpClientInterface` seam means the eventual fix touches
one class, but it needs a build step in the release workflow.

---

## 10. A WP-Cron safety net for settlement

**Now.** Background settlement relies entirely on Action Scheduler. If Action
Scheduler is absent, `NullScheduler` degrades to browser-driven settlement; if
Action Scheduler is present but its queue is wedged, checks simply stop.

**Should be.** A low-frequency sweep that finds pending non-custodial orders
whose checks have stalled and re-queues them, bounded to a small batch.

**Why it was not done now.** Action Scheduler ships with WooCommerce and is
well exercised; a wedged queue is a WooCommerce-wide problem a merchant would
notice through other symptoms. The sweep is redundancy, not a fix for a known
failure.

**Note on what it cannot fix.** WP-Cron only advances on a page visit, so on a
site with no traffic neither mechanism runs. That case is unsolvable in-process
and is why the pay page still polls.

---

## 11. An object-cache fast path for the lock

**Now.** `DbLock` always writes to `wp_options`. It is correct everywhere,
which is why it was chosen.

**Should be.** When a persistent object cache is installed, try `wp_cache_add()`
first and fall back to the database. That is one round trip instead of a write
on the hot path.

**Why it was not done now.** It is an optimisation with a second code path to
cover, and the database path is not a bottleneck at the volumes this handles.
Note that `wp_cache_add()` is only atomic *with* a persistent object cache —
using it unconditionally would silently disable the lock on a default install.

---

## 12. Stablesats for non-custodial accounts

**Now.** Non-custodial is Bitcoin-only. The settings screen still shows the
wallet-type selector, which has no effect in this mode.

**Should be.** Either support USD-pegged payments, or hide the control when it
does not apply.

**The second half is worth doing regardless** — a setting that silently does
nothing is a support ticket waiting to happen — and is small.

---

## 13. Rename `init_blink_plugin()`

**Now.** The bootstrap declares an unprefixed global function, currently
silenced with a `phpcs:ignore`.

**Should be.** `blink_init_plugin()`.

**Why it was not done now.** Renaming a shipped global function would break any
site that unhooks it by name. Low risk, non-zero, and unrelated to this work.

---

## 14. Keep the browser suite honest

**Now.** Three specs, run and mutation-tested, about three seconds. They cover
script loading, real QR rendering, and a real poll that navigates.

**Watch for.** The temptation to add specs here for things the PHP or Vitest
tiers can prove. The previous version of this suite grew to eighteen that way,
and most of them could not fail. The rule in `docs/testing.md`: if a browser
spec would still pass with the browser replaced by `curl`, it belongs in
another tier.

**One loose thread.** The end-to-end site converts the order total through
Blink's real public rate endpoint, so the suite depends on the network. It has
not been a problem, but a scripted rate would make it hermetic.

## 15. Cover `WcOrderRecord` and `BlinkApiSatsRateProvider` directly

Both are in the gated namespaces and are currently covered only incidentally,
through tests aimed at other classes. They deserve their own tests, or the gate
will fail the moment an unexercised branch appears in either.

---

## Deliberately not planned

**A public suffix list for the SSRF check.** The containment rule is tighter
and needs no data file. See [ADR 3](architecture/adr/0003-host-containment-not-psl.md).

**BOLT11 signature verification.** Binding plus the preimage check gives what
is actually needed without a secp256k1 dependency. See
[ADR 1](architecture/adr/0001-no-signature-verification.md).

**A sliding-window rate limiter.** Aligned windows admit a boundary burst
twice, in exchange for a single-statement atomic counter. For abuse mitigation
that is the right trade.

**A DI container.** Eleven services and one wiring class do not need one.
