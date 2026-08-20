# 3. Host containment instead of a public suffix list

Accepted, 2026-08.

## Context

Every URL an LNURL server returns is fetched by the shop's own server, so it
must be constrained to prevent server-side request forgery.

The previous rule compared the last two labels of the target host against the
address host. For `pay.example.co.uk` that reduces to `co.uk`, so every
`.co.uk` host on the internet passed. The same held for `.com.au`, `.co.jp`,
`.github.io` and every other multi-label suffix.

## Decision

Require the target host to equal the address host, or be a subdomain of it.

```php
$host === $address->host || str_ends_with($host, '.' . $address->host);
```

## Rationale

A public suffix list is the general solution, but it is a large file that ships
with the plugin and goes stale.

It is also not needed, because the correct rule here is _tighter_ than "same
registrable domain". An address at `blink.sv` may legitimately be served from
`blink.sv` or `api.blink.sv`; nothing in LUD-06 or LUD-16 entitles it to
`blink.io`, and no real provider does that.

## Consequences

- Two lines, no data file, no staleness.
- A provider serving LNURL from a genuinely different domain needs the
  `blink_lnurl_extra_allowed_hosts` filter — explicit and per-site, which is
  the right shape for a deliberate exception.
