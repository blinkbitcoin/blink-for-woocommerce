# 5. Keep WordPress out of the unit tier

Accepted, 2026-08.

## Context

The goal was 100% line *and branch* coverage on new code. The usual way to unit
test a WordPress plugin is Brain Monkey, which fakes WordPress functions.

## Decision

The unit tier does not load WordPress and does not fake WordPress functions.
Domain classes receive their collaborators as interfaces; thin adapters that
exist only to reach a WordPress primitive are tested in the integration tier.

## Rationale

This was forced rather than chosen. Brain Monkey works through Patchwork, which
must instrument `src/` to intercept a function call, and that instrumentation
inflates Xdebug's branch counts — a two-line class measured 30 branches at 40%
covered. Excluding `src/` from Patchwork fixes the counts and breaks the
mocking entirely. There is no configuration that yields both working mocks and
trustworthy branch data.

Given a hard branch gate, the mocking had to go.

## Consequences

- Unit tests run in milliseconds and cannot be affected by WordPress state.
- The seams are not optional: a class that reaches for `get_option()` cannot be
  unit tested, which is a useful forcing function.
- `@codeCoverageIgnore` is not needed anywhere. The adapters that would have
  been exempted are genuinely covered, just in the integration tier.
- Brain Monkey stays in `require-dev` but must not be used on a gated file.
