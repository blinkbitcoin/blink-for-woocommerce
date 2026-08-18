# Seams

The non-custodial code takes its collaborators as constructor arguments, typed
against interfaces. This document says why each seam exists, because "for
testability" on its own is not a reason anyone can act on.

## The rule

Production code in `src/NonCustodial`, `src/Http` and `src/Support` never calls:

- `time()` — use `ClockInterface`
- `wp_rand()` / `rand()` — use `RandomSourceInterface`
- `gethostbynamel()` / `dns_get_record()` — use `DnsResolverInterface`
- `new \GuzzleHttp\Client()` — use `HttpClientInterface`
- `get_option()` / `update_option()` / any WordPress function

The last one is the strictest and the least obvious, so it has its own section
below.

## The seams

| Interface | Why it exists |
|---|---|
| `ClockInterface` | Invoice expiry, lock TTLs and the retry schedule all branch on the current time. The interesting cases are exactly on the boundaries, and the only honest way to test a boundary is to stand on it. |
| `HttpClientInterface` | Every LNURL failure mode — timeout, 5xx, malformed body, oversized body, redirect — has different consequences. Driving them from a fake is the difference between covering them and hoping. |
| `DnsResolverInterface` | The private-address table has around twenty entries, several of which (CGNAT, IPv4-mapped IPv6) exist because the PHP defaults miss them. Real DNS would make that suite slow and non-hermetic. |
| `RandomSourceInterface` | Retry jitter is deliberately random. A scripted source makes the schedule exact, and the fake throws when its script runs out, so an unexpected extra draw fails loudly instead of silently reusing a value. |
| `LockInterface` | Lets single-flight behaviour be tested in-memory, while `DbLock` is tested against real MySQL where its atomicity claim actually means something. |
| `RateLimiterInterface` | Same split: budget logic in memory, the atomic counter against a real database. |
| `SchedulerInterface` | Separates *what work was scheduled* from *whether the queue ran it*. The unit tests assert the schedule; the integration tests assert Action Scheduler executes it. |
| `SettlementOutcomeApplier` | Lets the scheduler move a WooCommerce order without the scheduler knowing what WooCommerce is. |
| `OrderRecord` | Narrows `WC_Order` to the six operations the plugin actually performs, so invoice storage is testable without booting WooCommerce — and it is obvious that nothing reaches for order state it has no business reading. |
| `LnurlClientInterface` | Lets settlement be tested against scripted protocol responses rather than a server. |
| `SatsRateProviderInterface` | The one part of the flow still talking to Blink's GraphQL API. Behind an interface, invoice creation is testable without it, and the rate source can be swapped. |

## Why the unit tier has no WordPress at all

This one was discovered rather than designed.

Brain Monkey is the usual way to fake WordPress functions in a unit test. It
works through Patchwork, which must instrument `src/` to intercept a function
call — and that instrumentation inflates Xdebug's branch counts. A two-line
class measured **30 branches, 40% covered**. Excluding `src/` from Patchwork
fixes the counts and breaks the mocking entirely, so there is no configuration
that gives both.

Since a hard branch gate is the goal, the unit tier is WordPress-free by
construction instead:

- **Domain classes** take collaborators as interfaces and never call WordPress.
  They are unit tested, and hold the coverage gate.
- **Thin adapters** (`WcLogger`, `WpRandomSource`, `SystemDnsResolver`,
  `DbLock`, `DbRateLimiter`, `ActionSchedulerAdapter`, `WcOrderRecord`) exist
  only to reach a WordPress or WooCommerce primitive. They are tested in the
  integration tier against the real functions.

The pleasant side effect is that `@codeCoverageIgnore` is not needed anywhere:
the code that would have been exempted is genuinely covered, just in a different
tier.

## Wiring

`Blink\WC\Services` is the only class that knows how to build the graph — about
sixty lines of memoised factory methods, no container, no reflection.

WooCommerce constructs the gateway itself, so the gateway takes the graph as an
optional constructor argument that defaults to the shared instance:

```php
public function __construct(?Services $services = null) {
  $this->services = $services ?? Services::instance();
}
```

Every factory result passes through a `blink_service_<name>` filter, which is
how the integration tests substitute fakes — through the same mechanism a site
owner would use, not a test-only back door.

The Action Scheduler callback is registered in the plugin bootstrap rather than
on the gateway, because the gateway is only constructed on checkout-ish
requests while the queue runs in its own request where no gateway exists.
