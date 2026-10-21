# ADR 0010 — Generic, code-defined event types

**Status.** Accepted (proposed and confirmed by the owner on 2026-10-21). Decisions D-20261021-19 to -23.

**Context.** The app was built for Halloween trick-or-treating. Neighborhoods also want a Christmas lights map and a garage sale map. These have different listing fields, dates, filters, statuses and copy. Claims, sign-in, abuse controls, moderation, privacy and the map are the same for all of them. Nothing about a particular neighborhood may go into code (D-20261018-01).

**Decision.**
- An event type is a code module with a PHP side and a JavaScript side, selected by `event.type` in the community settings. It defines the listing fields and their server-side validation, filters, statuses, copy and icons. Halloween becomes the first event type.
- Organizers configure a type but cannot define new field types.
- One install runs one active event at a time. Claims (address ownership) are independent of the event and survive switching; each listing belongs to one event season.
- Type-specific listing fields live in a validated `details` JSONB column next to the relational core columns. The Halloween columns are copied in place and kept for one minor release so a rollback still works.
- Multi-day events have a date range with daily hours; a listing chooses its own days and hours.
- No photos, contact details or payment handles on listings.

**Consequences.** New event types reuse the claim, sign-in, moderation, route and privacy machinery unchanged. Every type's fields stay validated on the server and labelled accessibly. Adding a type is a code change with tests, not an organizer setting. One JSONB column needs careful validation, and SQL filters on it need indexes as types grow.

**Alternatives considered.**
- Organizer-defined custom forms: these cannot be validated or labelled accessibly, and they invite collecting personal data.
- One table per event type: more migrations and duplicate queries for the same claim/listing life cycle.
- Several simultaneous events per install: more complex sessions, maps and moderation for little gain at neighborhood scale.
