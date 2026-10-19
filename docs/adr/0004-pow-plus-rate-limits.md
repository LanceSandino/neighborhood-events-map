# ADR 0004 — Proof-of-work plus persistent rate limits

**Context.** Link requests and review requests send email; abuse would harm deliverability and neighbors. No third-party CAPTCHA is wanted.

**Decision.** `GET /api/pow?purpose=…` issues a 120-second, purpose- and client-bound challenge (128-bit salt) with adaptive difficulty (base `POW_DIFFICULTY`, +1/+2 for busy clients). The browser solves `SHA-256("<purpose>:<id>:<salt>:<nonce>")` for leading zero bits in a Web Worker. The server re-computes, enforces purpose, client binding, expiry, 5 failed attempts and single atomic consumption. Independently, `Throttle` keeps sliding windows in PostgreSQL (advisory-locked per key, HMAC-hashed keys) per email, IP, address and globally, returning 429 with `Retry-After`.

**Consequences.** Bulk abuse costs CPU *and* hits limits that survive restarts. Shared-IP neighborhoods may need higher per-IP limits (env).

**Alternatives.** CAPTCHA services (privacy, accounts), in-memory limiter (lost on restart), PoW alone (insufficient).
