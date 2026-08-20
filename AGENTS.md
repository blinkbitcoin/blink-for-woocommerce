# Engineering instructions for agents

This file applies to the entire repository. It is the short, enforceable
contract for automated contributors; the linked documents contain the full
rationale. Preserve existing user work, inspect the working tree before editing,
and never broaden a change beyond the requested scope without explaining why.

## Start here

Read the documents relevant to the change before writing code:

| Document                                                                                                               | Read when                                                                |
| ---------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| [CONTRIBUTING.md](CONTRIBUTING.md)                                                                                     | Always: setup, commands, formatting and review expectations              |
| [docs/testing.md](docs/testing.md)                                                                                     | Changing production code, tests, CI, fixtures or coverage                |
| [docs/architecture/seams.md](docs/architecture/seams.md)                                                               | Adding dependencies, services, adapters or WordPress/WooCommerce calls   |
| [docs/architecture/non-custodial-lightning-address.md](docs/architecture/non-custodial-lightning-address.md)           | Touching checkout, invoices, settlement, order state or Action Scheduler |
| [docs/security/ssrf-policy.md](docs/security/ssrf-policy.md)                                                           | Accepting or fetching any URL, hostname, address or remote response      |
| [docs/architecture/adr/0004-action-scheduler-settlement.md](docs/architecture/adr/0004-action-scheduler-settlement.md) | Changing background work, retries, queue health or settlement modes      |
| [docs/releasing.md](docs/releasing.md)                                                                                 | Changing versions, packaging, generated release files or CD              |
| [docs/future-work.md](docs/future-work.md)                                                                             | Changing dependencies or intentionally deferring work                    |

The supported floors are PHP 8.1, WordPress 6.5, WooCommerce 9.3.2 and Node 24.
Code must also work against the latest stable WordPress and WooCommerce versions
covered by CI. Do not use a newer API merely because the static-analysis stubs
contain it; guard it or stay within the declared compatibility range.

## Non-negotiable payment invariants

- Uncertainty must never cancel, expire, fail or complete an order. A timeout,
  transport error, malformed response or rejected URL proves neither payment nor
  non-payment. Leave the order pending for retry or human review.
- Never mark an order paid from browser state, a redirect, a query parameter or
  an unverified remote boolean. Settlement is server-side and requires the
  protocol evidence already modelled by the domain: invoice binding, exact
  amount/currency, payment hash and valid preimage.
- Payment processing and settlement callbacks must be idempotent. Retries,
  duplicate webhooks, multiple tabs and concurrent Action Scheduler workers must
  not double-complete an order, duplicate stock changes or repeat side effects.
- Preserve WooCommerce lifecycle semantics. Apply terminal state through the
  existing order-status boundary so `payment_complete`, stock, notes and public
  hooks remain coherent.
- Preserve the characterized custodial flow unless the task explicitly changes
  it. A non-custodial improvement must not silently alter API-key checkout.
- A payable non-custodial invoice must retain a durable settlement path. The
  customer closing every page is a required scenario, not an edge case.
- Use integers for satoshi/millisatoshi amounts and explicit rounding at currency
  boundaries. Do not introduce floating-point accounting.
- Closed state spaces belong in enums or named constants. Use WooCommerce and
  Action Scheduler constants where their APIs provide them; do not replace them
  with duplicated string literals to satisfy tooling.

## Security requirements

Treat checkout input, WordPress globals, order metadata, webhooks, Lightning
providers, DNS, HTTP bodies and browser responses as untrusted.

### OWASP planning and review gate

Security is part of design and review for every change, not a final scanner. Use
the current stable [OWASP ASVS](https://owasp.org/www-project-application-security-verification-standard/)
as the verification-requirements catalogue, the
[OWASP Top 10](https://owasp.org/www-project-top-ten/) as a risk-awareness
checklist, and the applicable
[OWASP Cheat Sheet](https://cheatsheetseries.owasp.org/) for implementation
detail. Do not claim that the plugin is “OWASP compliant” without a scoped,
versioned assessment and evidence for every applicable requirement.

During planning, and again during review:

1. Identify assets (money, order state, credentials, customer data), trust
   boundaries, entry points, privileged operations, outbound calls, stored data
   and failure modes changed by the work.
2. Describe likely abuse cases and the control that prevents or contains each
   one. Include replay, concurrency, confused-deputy behavior and unavailable or
   malicious dependencies—not only the happy path.
3. Review every applicable OWASP Top 10 category:
   - **Broken access control:** capabilities, order ownership, guest possession
     proofs, tenant/order isolation and server-side enforcement.
   - **Security misconfiguration:** safe defaults, production debug behavior,
     feature declarations, exposed endpoints and least-privilege permissions.
   - **Software supply-chain failures:** package/action provenance, lockfiles,
     install scripts, advisories and the exact production artifact.
   - **Cryptographic failures:** TLS, secret handling, constant-time comparison,
     payment-hash/preimage validation and no home-grown cryptography.
   - **Injection:** prepared SQL, contextual output encoding, strict structured
     input, safe command arguments and no evaluation/deserialization of data.
   - **Insecure design:** explicit trust boundaries, fail-safe outcomes,
     idempotency, rate limits, bounded work and recovery paths.
   - **Authentication failures:** API/webhook credential validation, nonce scope,
     replay resistance and credential lifecycle.
   - **Software or data integrity failures:** authenticated release inputs,
     validated remote payment data, immutable released artifacts and guarded
     update paths.
   - **Security logging and alerting failures:** actionable security events,
     queue-health visibility, no secret/PII leakage and no silent failure.
   - **Mishandling exceptional conditions:** deny or defer safely, clean up locks,
     preserve order recoverability and test timeouts, malformed data and partial
     failure.
4. Add adversarial tests at the lowest useful tier and at the real framework
   boundary where the control depends on WordPress, WooCommerce, MySQL, HTTP or
   Action Scheduler. Record the risk and verification evidence in the PR or
   handoff.

Any change to an endpoint, permission, payment decision, parser, URL policy,
database query, dependency, log, secret, build artifact or error path is
security-sensitive even when the task is not labelled “security.” An applicable
unmitigated high-impact risk blocks completion and must be reported explicitly.

### Authorization and request handling

- Validate structure and allowlisted values, then sanitize at the WordPress
  boundary. Unslash only the specific `$_GET`, `$_POST` or `$_SERVER` value being
  consumed; do not process an entire superglobal or use `$_REQUEST`.
- Nonces provide CSRF protection, not authorization. Administrative mutations
  also require the narrowest appropriate capability check.
- Guest endpoints must verify the existing possession proof (nonce/order key),
  confirm that the order belongs to this gateway and mode, compare secrets in
  constant time where applicable, and retain bounded rate limits.
- Escape at the last possible moment for the exact output context: HTML, HTML
  attribute, URL, JavaScript or JSON. Translation does not make a value safe.
- Redirect only to validated destinations with the appropriate WordPress safe
  redirect API. Do not trust a client-supplied return URL.

### Remote requests and SSRF

- Every LNURL well-known, callback and verification URL must pass `UrlPolicy`
  before each request. Do not add an alternate HTTP path that bypasses it.
- Preserve HTTPS enforcement, host containment, public-address validation, DNS
  pinning, disabled redirects, protocol restrictions, response-size limits and
  connect/total timeouts. A transport unable to honor a security control must
  refuse the request rather than degrade silently.
- Validate every field in every remote JSON response. Bound lengths and counts
  before expensive decoding or persistence. Never deserialize remote PHP data.
- Browser JavaScript talks to the store, not directly to an arbitrary Lightning
  provider. The store remains the policy and settlement authority.
- Use the OWASP
  [SSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html)
  during planning and review, while retaining this repository's stricter host
  containment and DNS-pinning rules.

### Persistence, secrets and supply chain

- Access orders through WooCommerce CRUD (`wc_get_order`, `WC_Order` getters,
  metadata methods and `save`). Never read or write order data through
  `wp_posts`, `wp_postmeta` or generic post APIs; HPOS may be authoritative.
- Narrow `wc_get_order()` results before passing them to code that requires a
  real `WC_Order`; refunds are distinct objects.
- Prepare SQL with `$wpdb->prepare`, keep counters/locks atomic, bound batch
  sizes, and avoid autoloading ephemeral rows.
- Never commit, echo or log API keys, credentials, nonces, order keys, payment
  preimages, personal data or complete remote payloads. Log safe identifiers and
  actionable state, with secrets redacted.
- Do not edit `vendor/`, `node_modules/` or minified third-party assets directly.
  Change manifests/lockfiles through Composer or npm, review install scripts and
  audits, and verify the production (`--no-dev`) distribution.
- A security fix needs a regression test proving the old exploit path fails. Do
  not weaken validation, authorization or transport policy merely to restore a
  happy-path test.

Report vulnerabilities through [SECURITY.md](SECURITY.md), not a public issue.

## WooCommerce engineering rules

- Support both classic checkout and Cart/Checkout Blocks. Keep block
  registration in the supported `AbstractPaymentMethodType` integration and
  keep its gateway name/data contract aligned with the classic gateway.
- Keep the HPOS compatibility declaration truthful. Any change that reads,
  queries or mutates orders must pass functional tests with both posts storage
  and HPOS; direct order-table SQL is forbidden unless isolated in a datastore
  adapter and proven under both modes.
- Register code on the correct WordPress/WooCommerce lifecycle hook. Do not
  reference plugin classes before Composer is loaded or WooCommerce APIs before
  WooCommerce initializes.
- Action Scheduler is the durable in-plugin queue. Schedule through the adapter,
  use the plugin group and documented API constants, and call its APIs only after
  Action Scheduler initialization. Workers must reload current order state,
  tolerate duplicate delivery and terminate with bounded retries.
- WP-Cron requires traffic unless the host invokes it externally. Never promise a
  hard settlement latency from an in-process plugin alone, and never move
  correctness back to an open browser tab to hide queue-health limitations.
- Public hooks, option names, metadata keys, gateway IDs and serialized enum
  values are compatibility surfaces. Change them only with an explicit migration
  and backward-compatibility tests.
- Keep user-facing strings translatable with the `blink-for-woocommerce` text
  domain. Regenerate the POT and WordPress.org readme preview when applicable.
- Avoid expensive network calls, unbounded queries or synchronous queue work on
  general page loads and admin screens. Remote calls require explicit timeouts.

Current upstream references, used only after the local support matrix and
architecture documents, are the official
[WooCommerce HPOS recipe book](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/),
[Checkout payment-method integration](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/checkout-payment-methods/payment-method-integration),
[Action Scheduler API](https://actionscheduler.org/api/) and
[WordPress plugin security handbook](https://developer.wordpress.org/plugins/security/).

## SOLID and clean-code boundaries

- **Single responsibility:** domain services decide one policy; adapters perform
  one framework/transport operation; the composition root wires them. Do not put
  protocol parsing, persistence, HTTP and order transitions into the gateway.
- **Open/closed and dependency inversion:** extend volatile boundaries through
  the existing narrow interfaces and wire implementations in `Services`. Domain
  code depends on interfaces, not WordPress globals, WooCommerce constructors,
  Guzzle or Action Scheduler functions.
- **Liskov substitution:** fakes and alternate implementations must preserve the
  production contract, including failure and timing semantics. A fake that is
  more permissive than production invalidates its tests.
- **Interface segregation:** add the smallest behavior a consumer needs. Do not
  grow a generic service locator or expose an entire `WC_Order`/HTTP client when
  a small domain operation suffices.
- Prefer immutable value objects, typed enums, explicit outcome types and early
  guard clauses. Avoid boolean flags whose meaning changes by caller, free-form
  status strings, hidden global state and ambiguous `null`/`false` results.
- Add an abstraction for a real policy or volatility boundary, not merely to
  reduce line count. Three clear statements are better than an indirection with
  no independent contract.
- Keep methods small enough to name their policy. Name reasons and units
  (`expiresAt`, `amountMsat`, `overdueBefore`), not generic data or flags.
- Production code must not contain test-only branches. Tests substitute through
  the same filters/interfaces available to real integrations.
- Comments and ADRs explain constraints and rejected alternatives; they do not
  narrate obvious syntax. Delete obsolete comments when behavior changes.

## Local development, formatting and static analysis

New code must be easy to exercise on a clean local checkout without Blink
credentials or live third-party services. Unit tests are fully hermetic;
functional and E2E suites use the repository's local WordPress, WooCommerce,
MySQL and fake LNURL fixtures. If a test needs public network access, replace the
dependency with a deterministic local boundary before considering the work done.

Bootstrap the supported toolchain and dependencies with:

```bash
nix develop
composer install
npm ci
```

Use the database and WordPress setup in [CONTRIBUTING.md](CONTRIBUTING.md) once;
after that, every tier has a direct Composer/npm command. Keep setup scripts
idempotent and failure messages actionable so a contributor does not need tribal
knowledge to reproduce CI.

Formatting and linting have distinct owners:

- **Prettier** is the only formatting authority for PHP, JavaScript, TypeScript,
  JSON, YAML and Markdown. `.editorconfig` matches it. Run
  `npm run prettier:fix`, then `npm run prettier:check`; do not hand-revert its
  canonical output or introduce a competing PHP style fixer.
- **PHPCS** checks WordPress security and API correctness, not layout.
- **PHPStan level 8** checks PHP types against the PHP 8.1 platform and configured
  WordPress/WooCommerce boundaries.
- **ESLint** checks JavaScript semantics; **TypeScript** checks Playwright and
  typed test/build code with `tsc --noEmit`.
- `php -l` across the plugin, `src`, `tests` and `bin` protects the complete PHP
  8.1–8.4 syntax matrix in CI.

Use a fast feedback loop while implementing:

```bash
npm run prettier:fix
composer lint
npm run lint
composer test:unit
npm run test:js
```

Then run the functional, HPOS, coverage and E2E gates required by the change.
Prefer focused test filters only during iteration; always finish with complete
affected suites. A test command must exit non-zero on failure and must not rely
on execution order, a pre-existing order, mutable public data or real market
rates.

Do not solve lint failures with broad exclusions, generated baselines, `mixed`,
unchecked casts or blanket suppressions. Correct the type/boundary or, when an
upstream declaration is demonstrably wrong, use the narrowest documented
adapter/configuration exception with a regression test.

## Test strategy and required coverage

Every defect starts with a test that demonstrably fails for the reported reason,
then passes after the fix. Cover behavior at every affected layer without
duplicating domain cases in slow tiers.

- **Unit:** keep domain code WordPress-free and exercise every outcome, branch,
  boundary (`-1`, exact, `+1`), error and idempotency path with deterministic
  clocks, randomness and HTTP fakes.
- **Functional/integration:** use real WordPress, WooCommerce, MySQL and Action
  Scheduler for hooks, CRUD, locks, rates, order transitions and adapters. Run
  the suite once with posts storage and once with `BLINK_TEST_HPOS=yes`.
- **JavaScript:** Vitest/jsdom covers pay-page state, timing, visibility,
  backoff, status and redirect behavior at 100% line and branch coverage.
- **E2E:** Playwright proves the browser contract on a real WooCommerce site. For
  settlement work it must close the payment page, keep WP-Cron disabled, run
  Action Scheduler through its real WP-CLI runner and prove that only the
  intended server-side mode settles the order.
- **Characterization:** behavior outside the requested change—especially the
  custodial gateway—must remain protected by regression tests.

New or changed code in the gated namespaces must retain 100% line and branch
coverage. Coverage is a floor, not a substitute for meaningful assertions. Do
not add `@codeCoverageIgnore`, grow `phpstan-baseline.neon`, add lint suppressions
or lower a threshold to make a gate green. Unreachable branches should usually
be removed; genuinely exceptional cases require documented review.

For production changes, run the relevant fast checks while iterating and the
complete affected matrix before handoff. Payment, settlement, security,
dependency, CI or release changes require the full matrix:

```bash
composer lint
npm run lint
composer test:unit
composer test:integration
BLINK_TEST_HPOS=yes composer test:integration
npm run test:js:coverage
XDEBUG_MODE=coverage composer coverage
XDEBUG_MODE=coverage composer coverage:gate
npm run test:e2e:setup
npm run test:e2e
```

See [docs/testing.md](docs/testing.md) for database variables, prerequisites and
the authoritative CI matrix. Documentation-only changes need formatting, link,
spelling and consistency checks rather than irrelevant runtime suites.

## CI/CD and review ergonomics

Automation should remove mechanical work from reviewers and give them compact,
trustworthy evidence. Humans should spend review time on behavior, risk and
design—not discovering whether formatting or a basic test failed.

- `CI OK` is the single aggregate required result. Keep the underlying lint,
  syntax, unit, functional matrix, HPOS, Plugin Check, security audit, coverage,
  JavaScript and E2E jobs independently visible and parallel where they do not
  share mutable state.
- Make required jobs deterministic: lockfile installs, explicit supported
  platform versions, hermetic fixtures, bounded timeouts and no dependence on a
  public API or market rate. Retries may diagnose infrastructure flakes; they
  must not normalize product-test flakiness.
- Fail close and early on objective quality gates. Only explicitly experimental
  compatibility jobs may be advisory, and their status must remain visible.
- Cache downloads and upload focused failure artifacts (coverage reports,
  Playwright trace/screenshot/video and useful logs) without caching mutable test
  state or leaking credentials/customer data.
- A workflow change must preserve least-privilege permissions, pin a deliberate
  action major/reference, account for supply-chain risk, and be tested through
  `workflow_dispatch` or a pull request before it can affect release automation.
- Never weaken, skip or mark a gate advisory merely to merge a failing change.
  Fix the defect or document a genuine external blocker with an owner and
  follow-up.
- CD consumes only a verified commit, checks version agreement, installs
  production-only dependencies and builds the distributable artifact. Publishing
  remains an explicit human action governed by `docs/releasing.md`.

Keep pull requests small and single-purpose. The PR description must let a
reviewer answer, without reverse-engineering the diff:

1. What failed or needed to change, and why?
2. What payment/order behavior and compatibility surfaces can be affected?
3. Which trust boundaries and OWASP risks apply, and what controls address them?
4. Which test was red before the fix and green after it?
5. Which local/CI gates ran, what passed, and what was not run?
6. Are migrations, rollback, generated artifacts, user documentation or release
   notes required?

Review in risk order: payment and order-state correctness; authorization and
security boundaries; HPOS/classic/Blocks compatibility; concurrency and failure
recovery; architecture and maintainability; tests; then naming/style. Formatting,
linting, static analysis and coverage percentages belong to automation. Use the
repository PR template and keep it current when quality gates change.

## Dependencies, generated files and releases

- Keep direct runtime, development and GitHub Actions dependencies current.
  Dependabot checks Composer, npm and Actions weekly; review those updates rather
  than allowing a permanent backlog. When dependency/tooling work is in scope,
  audit the complete direct set with `composer outdated --direct`,
  `npm outdated --depth=0`, `composer audit --locked` and both production-only
  and full npm audits.
- Take compatible patch and minor releases promptly. Evaluate every major and
  every pre-1.0 minor as a migration: read upstream changes, update config/code,
  inspect transitive/runtime impact and run the full matrix. Upgrade to the
  newest release compatible with every supported platform, not simply the
  numerically newest major.
- Keep the Node major synchronized across `flake.nix`, `package.json` engines and
  every CI/release workflow. Keep PHP analysis configured for the PHP 8.1 floor
  even when local tooling runs on PHP 8.3.
- Prove an incompatibility with Composer/npm resolution or a failing test and
  record the exact blocker and desired migration in `docs/future-work.md`.
  Revisit deferred items during later dependency audits; “deferred” does not
  mean permanently ignored.
- A dependency upgrade is incomplete until clean lockfile installs, static
  analysis, audits, coverage, functional/HPOS tests and E2E all pass as relevant.
  Do not hide migration failures behind baselines, ignores or compatibility
  shims that make an unsupported combination appear supported.
- `readme.txt` is the WordPress.org source; `docs/wordpress-org/readme.md` is
  generated with `npm run readme`. Translation templates are generated with
  `npm run i18n`. Review generated diffs and commit source plus output together.
- User-visible changes require matching entries in `readme.txt` and
  `changelog.txt`. Architecture or trust-boundary changes require the relevant
  document or ADR to change in the same work.
- Never create a tag, publish a GitHub release, write to WordPress.org SVN,
  rotate secrets or otherwise deploy without explicit user authorization.
  Follow [docs/releasing.md](docs/releasing.md) exactly when authorized.

## Definition of done

A change is done only when its behavior is complete, the smallest appropriate
design is used, security and compatibility invariants still hold, tests prove
the failure and fix at the right tiers, required quality gates pass, generated
artifacts and documentation are current, and no unrelated user changes were
discarded. Report any unrun gate, residual warning or externally blocked
verification explicitly.
