# Testing

Three tiers, each answering a different question.

| Tier | Question | WordPress? | Speed |
|---|---|---|---|
| Unit (`tests/Unit`) | Does this class behave correctly, including its edge cases? | No | milliseconds |
| Integration (`tests/Integration`) | Does it work against real WordPress, WooCommerce and MySQL? | Yes | ~1 minute |
| End to end (`tests/e2e`) | Does a customer actually get paid through? | Yes, in a browser | minutes |
| JavaScript (`tests/js`) | Does the pay page behave? | jsdom | seconds |

## Running them

```bash
composer test:unit          # no setup needed
npm run test:js             # no setup needed

# Integration needs a database and the WordPress test library:
bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 6.6 true
bash bin/install-woocommerce.sh 9.3.2
composer test:integration

# Coverage, which requires Xdebug:
XDEBUG_MODE=coverage bin/run-coverage.sh
composer coverage:gate

npx wp-env start && npm run test:e2e
```

`nix develop` provides PHP 8.3 with Xdebug, Composer, Node, subversion and a
MySQL client. Xdebug matters: **pcov cannot produce branch coverage at all**, so
it is not a substitute for the gate.

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

| Fake | Notes |
|---|---|
| `FakeClock` | `freezeAt()` and `travel()`. Use it to stand exactly on a boundary. |
| `FakeHttpClient` | Scripted responses plus a call log. `onRequest()` fires *during* a request — see below. |
| `FakeDnsResolver` | Host → addresses. |
| `FakeRandomSource` | Scripted floats; **throws when exhausted**, so an unexpected draw fails loudly. |
| `ArrayLock` / `ArrayRateLimiter` | In-memory, same expiry semantics as the database versions. |
| `FakeScheduler` | Records what was scheduled without running it. |
| `FakeOrder` | Implements `OrderRecord` in memory. |
| `SpyLogger` | Asserts that a swallowed failure was still reported. |
| `FixedSatsRateProvider` | Keeps the conversion off the network. |

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

**Background settlement.** Integration tests fire the scheduler's hook directly
with `do_action(SettlementScheduler::HOOK, $orderId)`, which is what Action
Scheduler does when it runs the queue. A test that never touches the AJAX
endpoint and still sees the order settle *is* the "customer closed the laptop"
case.

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

| File | Symbol | Reason | Reviewer |
|---|---|---|---|
| _(none)_ | | | |

The register is empty, which is the point: the code that would have needed
exemptions is covered in the integration tier instead.

## Writing a test

Name it after the behaviour, not the method. `test_an_unreachable_endpoint_never_cancels_an_order`
says what breaks if it fails; `test_poll_2` does not.

Where a test exists because of a specific defect, say so in a docblock. Several
tests in this suite look redundant until you know they are the only thing
standing between a customer and a cancelled paid order.
