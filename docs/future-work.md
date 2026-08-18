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
Timeouts were added, and the `blink_api_http_handler` filter lets a test
substitute the Guzzle handler, which is what makes the custodial paths
testable today. That is a seam, not the seam: the client still builds its own
Guzzle instance and does not go through `HttpClientInterface`.

**Should be.** It takes `HttpClientInterface`, like everything in the
non-custodial path.

**Consequences of not doing it.** The DNS pinning,
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

## 7. Extend branch coverage to the WordPress adapters

**Done since.** The coverage job used to take tens of minutes and never
completed in CI. The integration tier is now measured for lines only, and the
whole gate runs in about 30 seconds. See `docs/testing.md`.

**Now.** That was the option this document called a last resort, and taking it
should be recorded honestly: branch coverage is no longer measured for the
classes only the integration suite reaches — the thin adapters onto WordPress.
They are held to 100% lines, and their conditional logic is deliberately thin,
but a branch added to one of them today would not be caught.

**What would fix it.** A third PHPUnit tier: the adapter tests only, run under
path coverage. They are a small fraction of the suite, so the cost would be a
few seconds rather than twenty minutes, and the gate could require branches
everywhere. The obstacle is that adapter coverage is currently spread across
behavioural tests too, so the split needs those tests reorganised first.

**Meanwhile**, the cheaper move — used for `SystemDnsResolver::lookupAaaa()` —
is to pull a branch worth pinning out of the adapter and into a seam the unit
tier can reach.

**Applied since.** Both decisions this branch added to `BlinkLnGateway` were
moved out rather than left in the adapter: routing an order by its stored
account type is now `InvoiceRepository::resolvesNonCustodial()`, and whether an
existing invoice may be shown again is now `InvoiceReusePolicy` — five branches
that decide whether a customer sees a QR code that cannot be paid. Both are
under the coverage gate. The section stays open: the third tier still does not
exist, so the gateway's remaining conditionals are still unmeasured.

---

## 7b. Remove the subversion dependency from CI

**Now.** `bin/install-wp-tests.sh` fetches the WordPress test library with
`svn export`, and the GitHub runner images no longer ship subversion, so every
cache-miss job installs it from apt first. That step hung for 25 minutes on an
apt mirror and took three jobs down in a single run.

It is now skipped on a cache hit, bounded to five minutes and retried three
times, which contains the damage. It does not remove the dependency: a run that
genuinely needs the library still depends on an Ubuntu mirror and on
develop.svn.wordpress.org being reachable.

**What would fix it.** The `wp-phpunit/wp-phpunit` Composer package ships the
same test library as a normal dependency, installed by the `composer install`
the job already runs. That drops both the apt step and the svn fetch, and the
library gets versioned in `composer.lock` like everything else.

It is not a drop-in: `WP_TESTS_DIR` and the PHPUnit bootstrap both point at the
svn layout, and `install-wp-tests.sh` also seeds the database and downloads
WordPress core, which would still be needed. Worth doing, but as its own change
with the whole matrix green before and after.

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
Note that `wp_cache_add()` is only atomic _with_ a persistent object cache —
using it unconditionally would silently disable the lock on a default install.

---

## 12. Stablesats for non-custodial accounts

**Now.** Non-custodial is Bitcoin-only. `BlinkApiHelper::getConfig()` hardcodes
`wallet_type` to `bitcoin` in this mode and never reads the option.

**Done since.** The wallet-type selector said nothing about which mode it
applied to, so a merchant could pick USD, receive sats, and have no way to tell
why. Its description now says custodial-only, matching how the API key and
webhook fields already mark themselves.

**Should be.** Support USD-pegged payments, or hide the control outright rather
than labelling it. Hiding it means the settings screen branching on the saved
account type, which puts a new branch into `src/Admin` — the area §7 is about —
so it wants doing alongside that, not before it.

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

**Closed since.** The end-to-end site used to convert the order total through
Blink's real public rate endpoint, so a required check depended on a third
party being up and the amounts moved with the market. The harness now fixes the
rate at 100,000 sat per unit through the `blink_service_satsRateProvider`
filter, and the first spec asserts the invoice is for exactly `lnbc10m` -- so
if the real endpoint is ever reached again, that assertion fails rather than
the suite going quietly non-hermetic.

## 15. Cover `WcOrderRecord` and `BlinkApiSatsRateProvider` directly

**Done.** `BlinkApiSatsRateProvider` has its own unit tests; it had been
covered only incidentally, and the gate found it at 0% once the coverage job
could actually finish. `WcOrderRecord` is exercised throughout the integration
tier and is at 100%.

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
