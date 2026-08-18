## What and why

<!-- What changes, and what problem it solves. For a fix, say what went wrong
     before -- the diff shows what the code does now, but not the bug. -->

## Checklist

- [ ] Tests added or updated, and `composer test` passes
- [ ] `composer coverage:gate` passes (100% line and branch on gated files)
- [ ] `composer lint` and `npm run prettier:check` pass
- [ ] No new `@codeCoverageIgnore` (or the register in `docs/testing.md` is updated)
- [ ] `readme.txt` and `changelog.txt` updated for user-visible changes
- [ ] Docs updated if the flow, seams or trust boundary changed

## Anything touching settlement

- [ ] Uncertainty cannot cancel an order
- [ ] Every new outbound URL goes through `UrlPolicy`
- [ ] The custodial flow is unchanged, or the change is called out above
