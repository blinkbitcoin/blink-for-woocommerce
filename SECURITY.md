# Security policy

## Reporting a vulnerability

Please report security issues privately rather than opening a public issue.

- Email: security@blink.sv
- Or use GitHub's private vulnerability reporting on this repository.

Include what you did, what happened, and what you expected. If the issue
involves a payment being lost, misdirected or double-counted, say so — those are
triaged first.

Please give us a reasonable opportunity to release a fix before disclosing
publicly.

## Supported versions

The latest release on WordPress.org is supported. Fixes are not backported.

## Scope

Particularly interested in:

- anything that causes a paid order to be cancelled, or an unpaid order to be
  marked paid;
- anything that makes the shop's server fetch a URL it should not (see
  [docs/security/ssrf-policy.md](docs/security/ssrf-policy.md));
- anything that lets one customer learn about or affect another's order;
- anything that lets the polling endpoint be used to amplify traffic.

Known and documented limitations are listed in the SSRF policy document. A
report that restates one of those is still welcome, especially with a concrete
attack, but it is not news.
