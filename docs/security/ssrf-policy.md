# Outbound request policy

The non-custodial flow makes the shop's own server fetch URLs supplied by a
third party. That is a server-side request forgery primitive unless it is
constrained, because the shop is typically inside a hosting network with
private services and a cloud metadata endpoint on it.

## Trust boundary

**Trusted:** the Lightning address a merchant typed into the WooCommerce
settings screen.

**Untrusted:** everything the server behind that address returns — the callback
URL, the verify URL, the BOLT11 invoice, and every field of every JSON body.

Note that "trusted" is doing limited work even on the left: a merchant can type
any domain, including an internal one, so the well-known URL derived from the
address goes through the same checks as everything else. Without that,
configuring `alice@127.0.0.1` and pressing Save was enough to make the shop
fetch its own loopback interface, since the settings screen verifies the address
when it is saved.

## Rules

Every URL — well-known, callback, verify — must satisfy all of these before a
request is made:

1. **Parseable, with a host, under 2048 characters.**
2. **No credentials in the authority.** `https://user:pass@host/` is refused:
   it is a classic parser-confusion vector and no LNURL server needs it.
3. **HTTPS.** Plain HTTP is permitted only when the configured address is
   itself a local development host _and_ the target is too.
4. **Host containment.** The target host must equal the address host, or be a
   subdomain of it. `you@blink.sv` may be served from `blink.sv` or
   `api.blink.sv`; nothing entitles it to `blink.io`.
5. **No bare IP hosts** for a public address.
6. **Every resolved address must be public.** Not one of them — all of them.
7. **The request is pinned** to the addresses that were validated.

Beyond the URL itself: redirects are disabled, the response body is capped at
64 KiB and read as a stream, connect and total timeouts are 5 and 10 seconds,
and curl is restricted to HTTP(S) protocols.

### Why containment instead of a public suffix list

The rule this replaces compared the last two labels of each host. For an address
at `pay.example.co.uk` that reduces to `co.uk`, so **every `.co.uk` host on the
internet** satisfied the check. The same held for `.com.au`, `.co.jp`,
`.github.io` and every other multi-label suffix.

A public suffix list would fix it, but it is a large blob that has to ship and
stay current. The containment rule is _tighter_ than "same registrable domain"
and needs no list at all. Where a merchant genuinely serves LNURL from a
different host, `blink_lnurl_extra_allowed_hosts` is the explicit, per-site
escape hatch.

### Address ranges rejected beyond the PHP defaults

`FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` misses several ranges
that matter in hosting environments, so these are checked explicitly:

| Range           | Why                                                                                                                                  |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| `100.64.0.0/10` | Carrier-grade NAT; not covered by the reserved-range flag                                                                            |
| `::ffff:0:0/96` | IPv4-mapped IPv6. `filter_var` does not apply IPv4 private ranges to the mapped form, so `::ffff:127.0.0.1` read as a public address |
| `64:ff9b::/96`  | NAT64, which wraps an IPv4 address the same way                                                                                      |
| `0.0.0.0/8`     | "This network"                                                                                                                       |

### DNS rebinding

Validating a host and then letting curl resolve it again is only advisory: an
attacker's resolver can return a public address for the check and
`169.254.169.254` for the request. The addresses that passed validation are
carried into the request as `CURLOPT_RESOLVE` pins, so the connection goes to
exactly what was checked.

## The polling endpoint

`wp_ajax_nopriv_blink_check_invoice` is public by necessity — guests check out,
so there is no logged-in user to authenticate. Its authorisation is therefore:

- a WordPress nonce,
- a constant-time comparison against the order key,
- a check that the order is actually a non-custodial Blink order,
- a per-IP request budget.

What this protects against: driving settlement checks for orders you do not
have the key for, and using the endpoint to amplify traffic at a third-party
server.

What it does not protect against: someone who already has a valid order key —
which is to say, the customer — learning whether that order is paid. That is
information they are entitled to.

Only `REMOTE_ADDR` is used for the per-IP budget. Honouring `X-Forwarded-For` by
default would let anyone lift their own limit by sending a header; sites behind
a proxy can supply the real address through the `blink_client_ip` filter.

## What is deliberately not checked

**The invoice signature.** The invoice arrives over TLS from a host inside the
merchant's own domain, so a valid signature would only prove that some node
signed it — not that it is the invoice that was asked for. Binding is what
matters, and it is checked: exact amount, payment hash matching the verify URL,
description hash matching the advertised metadata, mainnet, non-amountless, and
enough life left to be payable. At settlement, the preimage must hash to the
payment hash.

Skipping signature recovery also keeps a secp256k1 binding out of the dependency
list, which matters for a plugin installed on arbitrary shared hosting.

## Known limitations

- **Same-domain trust.** A merchant who configures an address is trusting that
  domain. If it is compromised, it can issue invoices payable to someone else —
  the description-hash binding limits substitution, but the merchant's own
  provider is inherently trusted.
- **Aligned rate-limit windows.** A burst straddling a window boundary can be
  admitted twice. This is abuse mitigation rather than billing, and the trade
  buys a single-statement atomic counter.
- **No response signature.** LUD-21 provides no way to authenticate the verify
  response beyond TLS. The preimage check is the compensating control.

## Reporting

Security issues should go to the address in [SECURITY.md](../../SECURITY.md)
rather than a public issue.
