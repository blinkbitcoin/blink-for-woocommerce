## What and why

<!-- What changes, and what problem it solves. For a fix, say what went wrong
     before -- the diff shows what the code does now, but not the bug. -->

## Risk and design

<!-- Keep this brief but explicit:
- Payment/order behavior and compatibility surfaces affected (classic checkout,
  Blocks, HPOS, hooks, stored metadata, serialized values).
- Trust boundaries and applicable OWASP risks, plus the controls that address
  them. Write "none" with a reason when genuinely not applicable.
- Migration, rollback or operational considerations.
-->

## Verification evidence

<!-- For a defect, name the test that failed before the fix and passed after it.
List the complete commands/results run locally or in CI. State any gate not run
and why; do not imply unrun checks passed. Include screenshots only when they
help review visible behavior. -->

## Author checklist

- [ ] The change is focused; unrelated refactors and formatter churn are absent
- [ ] Tests cover every affected tier: unit, functional/HPOS, JS and E2E as applicable
- [ ] The reported defect was reproduced red before being fixed green
- [ ] `composer lint`, `npm run lint` and PHP syntax checks pass
- [ ] Coverage gates pass (100% line and branch on gated PHP/JS code)
- [ ] No new `@codeCoverageIgnore`
- [ ] No unexplained PHPStan baseline growth, lint suppression or weakened gate
- [ ] Security/OWASP, dependency and production-artifact impact was reviewed
- [ ] `readme.txt` and `changelog.txt` updated for user-visible changes
- [ ] Docs/ADR updated if a flow, seam, compatibility surface or trust boundary changed

## Anything touching settlement

- [ ] Uncertainty cannot cancel an order
- [ ] Settlement remains server-authoritative, idempotent and safe after pages close
- [ ] Every new outbound URL goes through `UrlPolicy`
- [ ] The custodial flow is unchanged, or the change is called out above
