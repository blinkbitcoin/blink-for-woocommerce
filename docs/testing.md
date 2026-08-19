# Testing

Three tiers, each answering a different question.

| Tier                              | Question                                                    | WordPress?        | Speed        |
| --------------------------------- | ----------------------------------------------------------- | ----------------- | ------------ |
| Unit (`tests/Unit`)               | Does this class behave correctly, including its edge cases? | No                | milliseconds |
| Integration (`tests/Integration`) | Does it work against real WordPress, WooCommerce and MySQL? | Yes               | ~1 minute    |
| End to end (`tests/e2e`)          | Does the browser contract and closed-page worker flow hold? | Yes, in a browser | ~40 seconds  |
| JavaScript (`tests/js`)           | Does the pay page behave?                                   | jsdom             | seconds      |

## Running them

```bash
composer test:unit          # no setup needed
npm run test:js             # no setup needed

# Linting: PHP on one side, everything else on the other.
composer lint               # phpcs, phpstan, coverage-ignore register
npm run lint                # prettier, eslint, tsc --noEmit

# Integration needs a database and the WordPress test library:
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 6.6 true
bash bin/install-woocommerce.sh 9.3.2
composer test:integration

# Coverage, which requires Xdebug:
XDEBUG_MODE=coverage bin/run-coverage.sh
composer coverage:gate

# End to end: a plain WordPress served by PHP's built-in server, no Docker.
bash bin/install-e2e-site.sh
npm run test:e2e

# The plugin as WordPress.org receives it, which is what Plugin Check reads:
bash bin/build-dist.sh
```

`nix develop` provides PHP 8.3 with Xdebug, Composer, Node 24 LTS, subversion and
a MySQL client. CI uses the same Node major, while `package.json` rejects other
Node majors so local and hosted runs cannot silently drift apart. The JavaScript
suite and coverage gate run on Vitest 4; static PHP analysis runs on PHPStan 2.
Xdebug matters: **pcov cannot produce branch coverage at all**, so it is not a
substitute for the gate.

## The distribution is not the repository

`bin/build-dist.sh` applies `.distignore` to produce `build/blink-for-woocommerce`,
which holds the release file set: the plugin file, `src`, `assets`, `languages`,
`vendor` and the readme. The tests, build scripts, Nix flake and documentation
stay behind. The script copies the current local `vendor/` unchanged, however,
so a development install may still contain Composer development packages. The
deployment and manual ZIP workflows run `composer install --no-dev` first and
are authoritative when inspecting the exact production artifact.

Quality checks that speak for WordPress.org — Plugin Check in particular — run
against that directory, not the repository root. Pointed at the root they report
problems in files no shop receives, which is both noise and, when the check
blocks a merge, a demand to delete the development tooling.

The directory is named after the plugin slug deliberately: Plugin Check infers
the expected text domain from the directory name, so building into `dist` makes
every translated string look like a text-domain mismatch.

## Why the unit tier has no WordPress

Because Brain Monkey and a branch-coverage gate cannot coexist. See
[the seams document](architecture/seams.md) for the full explanation; the short
version is that Patchwork must instrument `src/` to intercept a WordPress
function, and that instrumentation makes Xdebug report a two-line class as
having 30 branches.

So: domain classes take their collaborators as interfaces and never touch
WordPress. Thin adapters that exist only to reach a WordPress primitive are
covered in the integration tier against the real functions.

**If you find yourself wanting to fake a WordPress function in a unit test, the
class under test is reaching for something it should be receiving.**

## The fakes

In `tests/Support/Fake`:

| Fake                             | Notes                                                                                   |
| -------------------------------- | --------------------------------------------------------------------------------------- |
| `FakeClock`                      | `freezeAt()` and `travel()`. Use it to stand exactly on a boundary.                     |
| `FakeHttpClient`                 | Scripted responses plus a call log. `onRequest()` fires _during_ a request — see below. |
| `FakeDnsResolver`                | Host → addresses.                                                                       |
| `FakeRandomSource`               | Scripted floats; **throws when exhausted**, so an unexpected draw fails loudly.         |
| `ArrayLock` / `ArrayRateLimiter` | In-memory, same expiry semantics as the database versions.                              |
| `FakeScheduler`                  | Records what was scheduled without running it.                                          |
| `FakeOrder`                      | Implements `OrderRecord` in memory.                                                     |
| `SpyLogger`                      | Asserts that a swallowed failure was still reported.                                    |
| `FixedSatsRateProvider`          | Keeps the conversion off the network.                                                   |

`tests/Support/Bolt11Encoder` builds syntactically valid invoices, which is
possible precisely because the decoder does not verify signatures. It gives
exact control over amount, tags and expiry; the specification's own vectors in
`tests/fixtures/bolt11/spec-vectors.json` prove the decoder handles real
invoices.

## Testing the hard parts

**Concurrency, without concurrency.** `FakeHttpClient::onRequest()` fires while
a request is notionally in flight and the lock is held, so the callback can
re-enter the code under test. That is an exact model of a second browser tab
arriving mid-request, and it is deterministic:

```php
$this->http->onRequest(function () use (&$reentrant): void {
  $reentrant = $this->service->poll($this->order);
});
$first = $this->service->poll($this->order);

$this->assertSame(1, $this->http->requestCount());
$this->assertSame(SettlementStatus::Unknown, $reentrant->status);
```

**The PHP-to-JavaScript contract.** `PayPageContractTest` renders a real pay page,
asserts the payload keys and DOM ids against explicit lists, and writes both to
`tests/fixtures/pay-page/`. The Vitest suite loads that fixture rather than a
hand-written copy, which had already drifted. Regenerate with
`BLINK_UPDATE_FIXTURES=1`.

This is what catches a payload key being renamed on one side only — a change
that previously left all 620 tests green while breaking every real pay page.

**Background settlement.** Integration tests fire the scheduler's hook directly
with `do_action(SettlementScheduler::HOOK, $orderId)`, which is what Action
Scheduler does when it runs the queue. A test that never touches the AJAX
endpoint and still sees the order settle _is_ the "customer closed the laptop"
case.

The integration suite also passes the plugin's real scheduled action to
`ActionScheduler::runner()->process_action()`. The browser E2E keeps WP-Cron
disabled, closes the payment pages, and records the reported browser-only
failure beside the worker-only result. Both orders remain pending while only
time passes; the test then invokes Action Scheduler's real WP-CLI runner and
requires only the worker-backed order to become paid. This separation prevents
a test request, fake hook dispatcher, or an open page from accidentally doing
the settlement work.

The mode matrix uses `SettlementMode` cases in PHP. At the browser boundary the
fixture exposes one typed constant object with the same three serialized values,
and the test-control plugin converts the submitted value with
`SettlementMode::tryFrom()`. This keeps raw mode strings out of scenarios and
makes an unsupported spelling fall back to the production default instead of
silently selecting a fourth behavior.

The E2E intentionally supplies the queue runner. Action Scheduler persists and
executes the work, but its default runner is initiated by WP-Cron. WordPress
cannot execute PHP when the store receives no requests, so a production store
that must settle within a bounded time needs its host to invoke WordPress cron
regularly.

**Time.** Travel the fake clock. Boundaries are tested at −1, 0 and +1.

**Backoff.** Script `FakeRandomSource` at 0.0, 0.5 and 1.0 to pin the bottom,
middle and top of the jitter window, then use a fixed seed for a property test
over many draws.

## Coverage

Two gates, both runnable locally.

`bin/coverage-gate.php` requires **100% line and branch** coverage for every
file matching `coverage-gate.json`. It reads php-code-coverage's own exported
object rather than the Cobertura XML, because Cobertura records only per-line
condition coverage — it reported one class as fully covered when the real
measurement was 60 of 61 branches.

`diff-cover` requires 100% line coverage of the diff in CI, which catches new
code in files outside the gate list.

**Path coverage is not gated.** It is combinatorial: `LnAddress` sits at 26%
paths while fully covered on lines and branches. Requiring it would mean writing
tests for combinations that cannot occur.

**Branch coverage comes from the unit tier.** The integration suite is measured
for lines only. Xdebug's path-coverage mode over a suite that boots WordPress
and WooCommerce for every test measured 16x slower than line coverage — 20+
minutes against 75 seconds — which was slow enough that the CI job never once
finished, and the gaps it existed to catch went unreported for weeks.

In practice this costs little: everything the unit tier covers is branch-gated,
and that is all the domain logic. What only the integration tier reaches are the
thin WordPress adapters — `DbLock`, `WcOrderRecord`, `OrderStatusApplier`,
`ActionSchedulerAdapter` — which are line-gated. Where an adapter had a branch
worth pinning, the branch was made reachable from the unit tier instead of left
unmeasured; `SystemDnsResolver::lookupAaaa()` is the worked example.

The whole gate now runs in about 30 seconds.

`coverage-gate.json` is a ratchet. Files may be added, never removed, so the
codebase converges without this PR having to fix every untested legacy file.

### When the gate fails

It names the file, the uncovered lines, and the unexecuted branches with the
method and line range. Three things it commonly means:

1. **A branch you forgot.** Write the test.
2. **A branch that cannot happen.** Delete it. Several were removed during this
   work: a port fallback unreachable because the scheme was already checked, a
   `stopped` guard already handled by clearing the timer, a ternary whose false
   side a preceding early-return made impossible.
3. **`match` on an enum.** PHP emits an unhandled-case arm that can never
   execute and can never be covered. Use an `if`/`elseif` chain.

### Coverage ignore register

`@codeCoverageIgnore` requires a `// coverage-ignore-reason:` comment of at
least twenty characters and an entry in this table. `composer lint:ignores`
checks both directions.

An ignore covering a conditional is rejected outright: if you cannot reach a
branch, delete it. These are **not** acceptable reasons, because all of them are
testable:

- `wp_send_json_*` exits — reachable via `WPAjaxDie*Exception`
- `wp_die()` in the webhook — same mechanism
- `wp_safe_redirect(); exit;` — testable through the `wp_redirect` filter
- `catch (\Throwable)` — the fake HTTP client throws whatever you want

| File     | Symbol | Reason | Reviewer |
| -------- | ------ | ------ | -------- |
| _(none)_ |        |        |          |

The register is empty, which is the point: the code that would have needed
exemptions is covered in the integration tier instead.

## What belongs in the browser suite

Almost nothing. The browser layer costs the most and proves the least, so it
only holds assertions that no other tier can make:

- the scripts enqueue and execute from inside `woocommerce_receipt_*`;
- the vendored QR library renders in a real DOM;
- a real poll, with a nonce the page minted, navigates the customer.
- after a real pay page closes, time and status reads cannot settle the order,
  while Action Scheduler's real WP-CLI runner can settle the worker-backed
  order without reopening that page.

The worker scenario is one cross-process acceptance test, not a second domain
suite. URL policy, invoice validation, settlement outcomes, budgets and
concurrency are still proven faster and more precisely in PHP or Vitest. An
earlier version of this suite had eighteen specs; sixteen duplicated tests
elsewhere, and most could not fail at all because the orders they created were
never taken through the gateway. If a browser spec would still prove the same
thing with the browser replaced by `curl`, it is in the wrong tier.

Every spec must be shown to fail. Break the thing it asserts and watch it go
red before trusting it.

## Writing a test

Name it after the behaviour, not the method. `test_an_unreachable_endpoint_never_cancels_an_order`
says what breaks if it fails; `test_poll_2` does not.

Where a test exists because of a specific defect, say so in a docblock. Several
tests in this suite look redundant until you know they are the only thing
standing between a customer and a cancelled paid order.
