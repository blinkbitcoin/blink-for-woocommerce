# 6. Guzzle is shipped unscoped

Accepted (as a known risk), 2026-08.

## Context

The plugin bundles Guzzle in `vendor/` and ships it to WordPress.org. WordPress
has no dependency isolation: if two plugins on the same site bundle different
major versions of the same library, whichever loads first wins and the other
may break in ways that are hard to diagnose.

## Decision

Continue shipping Guzzle unscoped for now, and record the risk here.

## Rationale

Scoping (php-scoper or Mozart) rewrites the namespace at build time, which
means adding a build step to the release workflow and to the zip that reviewers
read. That is a real change to how the plugin is produced, and it is orthogonal
to the settlement work this documentation accompanies.

The blast radius is also now smaller: all outbound HTTP in the non-custodial
path goes through `HttpClientInterface`, so replacing Guzzle — with scoped
Guzzle, or with `wp_remote_get` — is a change to one class rather than a change
scattered across the codebase.

## Consequences

- A conflict with another plugin bundling an incompatible Guzzle remains
  possible.
- The seam makes the eventual fix cheap, whichever direction it takes.
