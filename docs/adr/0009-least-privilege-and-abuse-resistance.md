# ADR 0009 — Least privilege in code, owner-scoped resident data, abuse budgets

**Status.** Accepted (2026-10-19, after the pre-release security audit).

**Context.** Roles were enforced only by `access_control` path prefixes, and each resident query wrote its own `user_id` condition. Nothing was wrong, but nothing made it hard to add an unscoped query or an admin route outside the prefixes. The audit also found budgets that failed requests could exhaust, and no limit on how many homes one resident could claim.

**Decision.**
- Two roles only: `ROLE_USER` (a verified resident: may manage their own homes, nothing else) and `ROLE_ADMIN` (organizer: everything). Organizers come from `app:admin:grant` or `ORGANIZER_EMAILS`. Controllers declare their role with `#[IsGranted]` *and* `access_control` covers their paths.
- Resident data goes through `App\Listing\ResidentHomes`: an object bound to one user whose every query starts from `claims c WHERE c.user_id = :owner` (DBAL QueryBuilder; no ORM, per D-20261018-02). A controller cannot express "home #N" without the owner condition.
- No Symfony voters for now: a resident has exactly one permission ("manage my own homes") and it is already structural. Add voters when a second resident permission appears.
- Abuse budgets: proof-of-work before anything; a response-time floor after it; per-IP (IPv6 /64), per-mailbox (aliases folded), per-address and global caps, spent only by work that is actually done (mail sent, valid review request); honeypot fields; a per-resident home limit.

**Consequences.** Adding a resident feature means adding a method to `ResidentHomes`; adding an organizer feature means adding it to an `#[IsGranted('ROLE_ADMIN')]` controller. Sign-in responses are slower by design (≈ 0.7–0.95 s).

**Alternatives considered.** Doctrine ORM entities with a repository filter (rejected: D-20261018-02 keeps DBAL; the QueryBuilder gives the same guarantee); voters for every resident action (deferred until there is more than one resident permission); an external identity provider sending the user in a header (still needs these roles in the app, so no gain for one neighborhood).
