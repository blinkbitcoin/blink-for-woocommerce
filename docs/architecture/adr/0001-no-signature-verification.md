# 1. Do not verify BOLT11 signatures

Accepted, 2026-08.

## Context

The non-custodial flow receives a BOLT11 invoice from a third-party LNURL
server. BOLT11 invoices are signed by the issuing node, and verifying that
signature is the obvious thing to reach for.

Doing so requires secp256k1 public-key recovery. In PHP that means either an
extension many shared hosts do not have, or a pure-PHP implementation of
elliptic-curve maths in a plugin that moves money.

## Decision

Do not verify the signature. Verify _binding_ instead:

- the amount matches what was requested, exactly;
- the payment hash matches the verify URL settlement is polled against;
- the description or description hash matches the metadata the address
  advertised;
- the invoice is on mainnet, carries an amount, and has enough life left to be
  paid.

At settlement, the preimage must hash to the payment hash.

## Rationale

A valid signature would prove that _some node_ signed the invoice. That is not
a fact the plugin needs. The invoice already arrived over TLS from a host
inside the merchant's own address domain, so the question is not "did a node
sign this" but "is this the invoice I asked for, and was it paid" — which is
exactly what the binding checks and the preimage answer.

## Consequences

- No secp256k1 dependency, and the decoder stays around 250 lines.
- A test can synthesise valid invoices, which is what makes the LNURL fixture
  server and the invoice edge-case tests possible at all.
- A compromised address domain can still issue invoices payable to itself. That
  is inherent: the merchant chose to trust that domain.
- The decision is stated in `Bolt11Decoder`'s class docblock and asserted by a
  test, so it cannot be mistaken later for an oversight.
