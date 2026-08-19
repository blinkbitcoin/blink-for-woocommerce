# Releasing

This is the operator runbook for publishing Blink for WooCommerce. A release
publishes code to WordPress.org, where shops may auto-update it, so use a commit
that has passed the complete `CI OK` job. Publishing a GitHub release is the
production deployment trigger; pushing a tag alone does not deploy the plugin.

The executable definitions are the
[plugin deployment workflow](../.github/workflows/deploy-plugin.yml),
[asset deployment workflow](../.github/workflows/deploy-assets.yml) and
[manual ZIP workflow](../.github/workflows/manual-zip-build.yml). If this
runbook and a workflow disagree, stop the release and update them together.

## Access required

The repository must have these GitHub Actions secrets:

- `SVN_USERNAME` and `SVN_PASSWORD`: credentials allowed to publish the
  `blink-for-woocommerce` plugin on WordPress.org.
- `PAT_GITHUB_TOKEN`: a GitHub token allowed to attach the generated ZIP to the
  release.

The release workflow has `contents: write` permission. Never print, copy into a
command, or put any of these credentials in release notes. Repository
administrators manage them under **Settings → Secrets and variables → Actions**.

## Prepare the release

1. Choose the next semantic version, for example `0.3.1`.
2. Update every version-bearing location:
   - the `Version` header and `BLINK_VERSION` constant in
     `blink-for-woocommerce.php`;
   - `Stable tag` and the changelog section in `readme.txt`;
   - the matching release notes in `changelog.txt`;
   - `version` in `package.json` and `package-lock.json`.

   `npm version 0.3.1 --no-git-tag-version` updates both npm files without
   creating the Git tag; substitute the chosen version.

3. Describe every user-visible change in both changelogs. Call out payment,
   order-state, compatibility and security changes explicitly.
4. Regenerate and commit derived documentation and translations:

   ```bash
   npm ci
   npm run i18n
   npm run readme
   ```

   `npm run readme` updates `docs/wordpress-org/readme.md`; it must agree with
   `readme.txt`. Review generated changes before committing them.

5. Open and merge a release-preparation pull request. Do not create the GitHub
   release until the exact commit intended for the tag has a successful
   required `CI OK` result.

The deployment workflow rejects a release when the Git tag, plugin `Version`
header and WordPress.org `Stable tag` differ. The additional version locations
are kept aligned for humans and build tooling even though the workflow does not
currently enforce them.

## Pre-release verification

Follow [testing.md](testing.md) for local setup. At minimum, run:

```bash
composer install
npm ci
composer lint
npm run lint
composer test:unit
npm run test:js:coverage
bash bin/build-dist.sh
```

Run the integration, HPOS, coverage and browser suites when their local
WordPress/MySQL prerequisites are available. Local checks are useful feedback,
but the authoritative release gate is the required `CI OK` job on the exact
release commit. It covers the supported PHP/WordPress/WooCommerce matrix,
HPOS, PHP and JavaScript coverage, browser E2E, dependency audits, translation
reproducibility and WordPress Plugin Check.

Inspect `build/blink-for-woocommerce` after `bin/build-dist.sh`. It applies the
same repository-file exclusions used for release, but it copies the current
local `vendor/` directory unchanged. A normal development installation can
therefore contain Composer development packages that the real deployment does
not ship. Use the manual **Build release zip** workflow when the exact
production-only artifact must be inspected before release.

## Publish

1. In GitHub, create a new release from the exact verified commit on `main`.
2. Create the tag as `v<version>` (for example `v0.3.1`). A tag without the `v`
   prefix also passes validation, but `v<version>` is the project convention.
3. Use `v<version>` as the release title and summarize the corresponding
   changelog entry.
4. Keep the release as a draft while checking the target commit, version and
   notes. Do not mark a production deployment as a pre-release: the CD workflow
   listens for the regular GitHub `released` event.
5. Select **Publish release** once. This is the production deployment action.

Publishing starts **Deploy Plugin to WordPress.org Repository**. Its `verify`
job checks version agreement, validates and audits the Composer lockfile, and
runs unit and WordPress/WooCommerce integration tests. Only after that job
passes does `deploy_to_wp_repository`:

- install production-only Composer dependencies;
- publish the plugin through the WordPress.org SVN repository;
- generate `blink-for-woocommerce.zip`; and
- attach that ZIP to the GitHub release.

Monitor both jobs to completion. A published GitHub release is not proof that
WordPress.org deployment succeeded.

## Verify production

After the workflow succeeds:

1. Confirm the GitHub release has the generated ZIP attached.
2. Download the ZIP and confirm it contains one top-level
   `blink-for-woocommerce` directory and no tests or development dependencies.
3. Confirm WordPress.org shows the new stable version and changelog.
4. Install or update the ZIP on a disposable WooCommerce site and complete one
   custodial and one non-custodial checkout smoke test when those paths changed.
5. Confirm the WordPress.org support page and GitHub Actions run do not report a
   packaging or deployment error.

## WordPress.org assets

Files under `.wordpress-org/` are deployed by a separate workflow on every
push to `main`. This updates banners, icons and screenshots; it does not publish
plugin PHP code or create a release. Review asset changes in a pull request just
as carefully as code because merging them starts the external update.

## Failure and recovery

- If `verify` fails, nothing is deployed to WordPress.org. Fix the cause in a
  new commit and release-preparation pull request. Do not move or reuse the
  failed tag; create the corrected release only from a verified commit.
- If deployment fails after verification, inspect the failed step before
  rerunning it. Determine whether the WordPress.org SVN commit or GitHub ZIP
  upload already completed so an automatic retry does not obscure a partial
  deployment.
- **Build release zip** is a manual workflow for inspecting or recovering the
  production-only packaged artifact. It does not publish to WordPress.org, and
  its artifact is retained for one day.
- There is no automated rollback. WordPress clients do not reliably downgrade
  after a bad release, so the normal recovery is a higher patch version with
  the fix. For a partial or corrupted SVN deployment, coordinate with the
  WordPress.org plugin team before changing SVN by hand.
- Never delete and recreate a published tag to replace its contents. Released
  tags and ZIPs must remain immutable and auditable.
