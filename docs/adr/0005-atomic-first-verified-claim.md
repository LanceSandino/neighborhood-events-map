# ADR 0005 — Atomic "first verified email wins"

**Context.** Two neighbors may try to claim the same address; no address is reserved before verification; there is no moderator queue.

**Decision.** A claim intent stores the draft without reserving anything. On verified POST, one transaction re-validates eligibility, inserts the address (self-entry) with `ON CONFLICT (normalized_address)`, then `INSERT INTO claims … ON CONFLICT (address_id) DO NOTHING RETURNING`. Only the winner inserts the listing; losers get `already_claimed` and a review option. The primary key is the single source of truth.

**Consequences.** Exactly one owner under concurrency (verified by an 8-process race test and an E2E race). Email control is not proof of residence — handled by manual disputes, transfers and hiding, all audited.
