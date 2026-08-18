# 2. Vendor a minimal BOLT11 decoder

Accepted, 2026-08.

## Context

Checking the returned invoice (ADR 1) requires decoding it. The plugin had no
decoder, and the invoice was previously trusted without inspection — a server
could return a 1-satoshi invoice for a 100,000-satoshi order and the plugin
would report the order paid.

## Decision

Write a minimal decoder in `src/NonCustodial/Bolt11/` rather than take a
dependency.

## Rationale

The PHP BOLT11 ecosystem is not a safe supply chain for a payments plugin: the
candidates are single-maintainer packages with small install bases, and several
pull in `bitwasp/bitcoin` or a secp256k1 binding.

And a dependency here is not invisible. `vendor/` ships into the WordPress.org
SVN tree, so it is code a plugin reviewer must read and a merchant must trust.
Roughly 250 commented, fully covered lines are easier to audit than a
transitive dependency graph.

Only a subset is needed — bech32, the amount in the human-readable part, and
four tagged fields — because signatures are not verified.

## Consequences

- One more piece of protocol code to own, offset by a test suite that runs
  every example invoice from the specification.
- The 90-character bech32 limit is deliberately not implemented: BOLT11 exempts
  itself from it, and enforcing it is the classic porting bug that rejects
  every real invoice. This is commented in the code.
