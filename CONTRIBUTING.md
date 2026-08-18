# Contributing

## Setting up

With [Nix](https://nixos.org/):

```bash
nix develop        # PHP 8.3 + Xdebug, Composer, Node 20, subversion, mysql client
composer install
npm ci
```

Without Nix you will need PHP 8.1+ with Xdebug, Composer 2, Node 20, `svn` and a
MySQL client.

A throwaway database for the integration suite:

```bash
docker run -d --name blink-wc-test-db \
  -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wordpress_test \
  -p 13306:3306 mysql:8.0

bash bin/install-wp-tests.sh wordpress_test root root '127.0.0.1:13306' 6.6 true
bash bin/install-woocommerce.sh 9.3.2
```

For a browsable site, `docker-compose up` still serves one on
<http://localhost:8080>.

## Running things

```bash
composer test:unit          # WordPress-free, milliseconds
composer test:integration   # real WP + WooCommerce + MySQL
composer test               # both
npm run test:js             # the pay page
npm run test:e2e            # browser, needs `npx wp-env start`

composer lint               # phpcs + phpstan + the coverage-ignore register
npm run lint                # prettier + eslint + tsc --noEmit

XDEBUG_MODE=coverage bin/run-coverage.sh && composer coverage:gate
```

## What the review will ask about

**Tests.** New code in `src/NonCustodial`, `src/Http`, `src/Orders` and
`src/Support` must reach 100% line and branch coverage. This is enforced, not
aspirational — see [docs/testing.md](docs/testing.md), including what to do when
the gate fails (sometimes the answer is to delete an unreachable branch).

**Seams.** Production code in those namespaces does not call `time()`,
`wp_rand()`, DNS functions, or construct an HTTP client. It receives them. See
[docs/architecture/seams.md](docs/architecture/seams.md).

**Formatting and JavaScript.** Prettier is the authority for every file it can
parse — PHP, JavaScript, TypeScript, JSON, YAML and Markdown. Run
`npm run prettier:fix` rather than hand-formatting; `.editorconfig` matches it.
PHPCS is configured for security and WordPress-API correctness only,
deliberately not style.

`npm run lint` also runs ESLint over every JavaScript file and `tsc --noEmit`
over the TypeScript. The type check earns its place because Playwright strips
types without checking them, so nothing else would notice a type error in the
end-to-end helpers.

**Anything touching money or settlement.** Read
[docs/architecture/non-custodial-lightning-address.md](docs/architecture/non-custodial-lightning-address.md)
first. The one rule that matters most: **uncertainty must never cancel an
order.** A verify endpoint that is unreachable tells you nothing about whether
the customer paid, and an order left pending for a human is recoverable where a
wrongly cancelled paid order is not.

**Anything fetching a URL.** Read
[docs/security/ssrf-policy.md](docs/security/ssrf-policy.md). Everything an
LNURL server returns is attacker-controlled and goes through `UrlPolicy` before
it is fetched.

## Commits and pull requests

Conventional commits (`feat:`, `fix:`, `test:`, `docs:`, `chore:`, `ci:`).

Explain _why_ in the body, especially for a behaviour change. A commit that says
what the code now does is less useful than one saying what went wrong before —
the reader can see the diff, but they cannot see the bug.

User-visible changes need entries in `readme.txt` and `changelog.txt`.

## A note on documentation

`README.md` is hand-maintained. `readme.txt` is the WordPress.org listing, and
`npm run readme` renders it to `docs/wordpress-org/readme.md` for preview. It
used to overwrite `README.md`, which destroyed any developer documentation put
there.
