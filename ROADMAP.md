# Roadmap

Neighborhood events map started as a Halloween trick-or-treat map. The next two event types are a **Christmas lights map** and a **garage sale map**, and since 0.15 several events can be shown at once. Both are *generic* event types: any neighborhood can pick one in its settings, and nothing about a particular neighborhood goes into code ([D-20261018-01](DECISIONS.md)).

This is a plan, not a promise of dates. Each stage ships as its own minor pre-release once it passes every quality gate ([D-20261019-18](DECISIONS.md)). The architectural choices below are recorded in [DECISIONS.md](DECISIONS.md) as D-20261021-19 to D-20261021-23 and in [ADR 0010](docs/adr/0010-generic-event-types.md).

## What stays the same for every event type

The parts that make the Halloween map safe are shared by every event type, unchanged:

- **One community per install; several events at once since 0.15** ([ADR 0011](docs/adr/0011-several-published-events.md)). A neighborhood that runs a lights map in December and a garage sale in May shows both side by side (one current event of each kind), visitors switch with tabs and the organizer picks the default. (Until 0.14: one active event at a time, switched between seasons.)
- **Homes are claimed, not events.** A claim is ownership of an address (first verified email wins, enforced by the `claims` primary key). It survives switching events, so a resident who listed their home for Halloween manages it for the lights map with the same sign-in.
- **Listings belong to one event.** Each season's listing is separate. Last season's details are never shown again by accident.
- **Passwordless sign-in, proof-of-work, rate limits, honeypots and the response floor** on every email-sending endpoint.
- **Organizer moderation**: hide and unhide, review requests, transfers, the audit log and address review.
- **Privacy**: no tracking or analytics; the visitor's location and saved routes stay on their device; public APIs never expose emails, owner ids or the unclaimed address inventory.
- **Maps**: the server-validated boundary and zones, the dimmed outside, and suggested stop order split by walkable areas. Route lines come only from a configured provider, never straight lines ([D-20261020-03](DECISIONS.md)).

## The event-type model

An **event type** is a small code module with a PHP side and a JavaScript side. It defines:

| Part | Halloween (today) | Christmas lights | Garage sale |
|---|---|---|---|
| Default event name | Halloween | Christmas Lights | Garage Sale |
| Dates | one evening | a date range, nightly hours | one to three days, daytime hours |
| Listing fields | candy, non-food, 21+, sensory details, scare level, stroller | display style, animated/flashing warning, music and radio station, walk-up or drive-by, treats | item categories, multi-family, early birds, payment kinds, free curb items |
| Quick filters | candy, sensory-friendly, 21+, saved | drive-by, walk-up, no flashing, music, saved | categories, open now, saved |
| Statuses | open, paused, out of candy, done | lights on, off tonight, done for the season | open, paused, sold out, closed |
| Copy | reminders, access labels, headline defaults | viewing etiquette, traffic and parking notes | sale etiquette, parking notes, prohibited items |
| Map icons | pumpkin, candy, teal pumpkin | lights, music, car | tag, categories |
| Route profile | walking | walking or driving | walking or driving |

Organizers choose the type and edit its settings and copy in the console. They cannot invent new field types. Every field stays validated on the server and has accessible labels ([D-20261021-19](DECISIONS.md)).

## What each new type needs

### Christmas lights map

- **Event and config:** `event.type: holiday_lights`; a date range (`starts_on`, `ends_on`) with nightly hours. Organizers may also set a “best night” for a neighborhood walk.
- **Listing fields:**
  - display style (classic, animated, synchronized to music, inflatables, walk-through yard);
  - a warning flag for flashing or strobing effects (photosensitivity);
  - music, with an optional FM frequency;
  - walk-up welcome or drive-by only;
  - treats or hot cocoa offered;
  - the nights the lights are on (a subset of the range) and the home's own hours.
- **Dates and hours:** “open now” uses the community time zone and the home's own nights and hours. A home can pause itself for one night (“off tonight”) without giving up the season.
- **Map and filters:** icons for display style; filters for drive-by and walk-up, no flashing, music and open tonight. The suggested order can be a **drive-by tour**: driving directions come only from a configured provider. Otherwise it is an ordered list with no lines, as today.
- **Moderation:** the same hide and review flow; organizer notes on traffic, parking and quiet hours shown on the About page.
- **Privacy:** no photo uploads in the first version (moderation and image metadata). A “drive-by only, please don't knock” flag respects households that only want to be seen.

### Garage sale map

- **Event and config:** `event.type: garage_sale`; one to three sale days with default hours. Organizers set the allowed item categories (from a fixed list) and community rules (for example “no early birds before 8 AM”).
- **Listing fields:**
  - item categories (furniture, kids and baby, clothing, tools, books and media, electronics, sports, household, free stuff);
  - multi-family sale;
  - early birds welcome;
  - payment kinds (cash, card, payment apps — the kind only, never a handle or phone number);
  - the sale days the home takes part in, and its hours on each.
- **Dates and hours:** per-day hours; “open now” and “open on Saturday” filters.
- **Map and filters:** category icons and filters; search over categories and notes. The suggested route works as today: walking stops grouped by walkable area, and driving between areas only through a provider.
- **Moderation:** report and hide flow for business sellers or prohibited items (weapons, recalled products), and a “sold out” status. Notes are checked on the server for phone numbers, emails and payment handles: the resident is asked to remove them before publishing.
- **Privacy:** no photos and no contact details on listings. The street address is public only after the resident opts in, exactly as today.

## Plan in stages

Each stage reuses the existing claim, listing, sign-in, moderation and route machinery and keeps every current test passing.

1. **Stage 0 — neutral naming (done in 0.7.0-beta.1).**
   The product is “neighborhood events map” and the community's event is the `event.name` setting. Persisted identifiers are unchanged ([D-20261021-18](DECISIONS.md)).
2. **Stage 1 — event-type abstraction, Halloween only (done in 0.10.0-beta.1).**
   - Add `event.type` (default `halloween`).
   - Move the Halloween fields, filters, statuses, copy and icons into a `halloween` event-type module on both the PHP and JavaScript sides.
   - Store type-specific listing fields in a validated `details` JSONB column next to the core columns ([D-20261021-21](DECISIONS.md)).
   - A migration copies today's Halloween columns into `details`; the old columns stay until the following minor release, so a rollback keeps working.
   - Done when the full suite and the e2e flows pass unchanged with Halloween expressed as the first event type.
   - Status: the registry, the Halloween module (PHP, browser, Twig fragments) and `listings.details` with its rollback trigger shipped in 0.10.0-beta.1; the public API answers, pages and screenshots are the 0.9 ones plus `event.type` ([D-20261031-01](DECISIONS.md) to [-04](DECISIONS.md), [event types](docs/architecture/event-types.md)).
3. **Stage 2 — multi-day events (done in 0.11.0-beta.1, completed by the types in 0.12 and 0.13).**
   - Add date ranges and daily hours to the event ([D-20261021-22](DECISIONS.md)).
   - A listing's own subset of days and hours.
   - “Open now” and “open on <day>” in the community time zone.
   - The season archive and the “last season's homes disappear” rule generalized to ranges.
   - Status: the core shipped in 0.11.0-beta.1 — events are rows of their own with a date range and default hours, listings belong to one event and have their own days and hours (windows), the schedule core checks windows for one-day, nightly and sale-day kinds, and the display status (“open now”, “later”, a status that holds for one day) is derived in the community time zone by the same rules in the server and the browser. Halloween stays a one-day event; the date picker and “open on <day>” filters come with the types that need them ([D-20261031-05](DECISIONS.md) to [-11](DECISIONS.md), [event types](docs/architecture/event-types.md)).
4. **Stage 3 — Christmas lights (done in 0.12.0-beta.1).**
   - The `holiday_lights` type: fields, filters, icons, statuses, copy and reminders.
   - The drive-by tour through a provider's driving profile, only when the organizer enables a provider.
   - Demo data: a fictional lights community under `examples/`.
   - Status: shipped in 0.12.0-beta.1 — organizers choose Christmas Lights under Organizer → Events (dates up to 62 nights, nightly hours, rules, best night, quiet hours, drive-by tour); residents pick their nights and hours, styles, drive-by or walk-up, music with an FM station, a flashing warning, treats or cocoa, and mark "off tonight" or "done for the season"; visitors get the midnight blue and gold theme, the season row, the filters (drive-by, no flashing reported, music, walk-up, open tonight, a night, a style) and statuses on their own clock; the fictional lights demo is `examples/demo-lights/` ([D-20261031-16](DECISIONS.md) to [-23](DECISIONS.md), [event types](docs/architecture/event-types.md), [organizer checklist](docs/runbooks/christmas-lights.md)).
5. **Stage 4 — garage sale (done in 0.13.0-beta.1).**
   - The `garage_sale` type: categories, days and hours, statuses, moderation rules and the server-side contact-detail check on notes.
   - Demo data: a fictional sale under `examples/`.
   - Status: shipped in 0.13.0-beta.1 — organizers choose Garage Sale under Organizer → Events (a public name such as "Community Rummage Sale", one to three sale days, default hours, the categories residents may list, an early-bird time, rules, a driving tour); residents pick their sale days with their own hours on each, categories, multi-family, early birds and payment kinds (the kind only), and mark "Paused today", "Sold out today" or "Closed"; a note with a phone number, email, payment handle or web address is refused and never stored; visitors get the teal and coral theme, the sale row, filters by category, open now, each sale day, multi-family and early birds, search over categories and notes, and statuses on their own clock; the fictional sale demo is `examples/demo-sale/` ([D-20261031-24](DECISIONS.md) to [-32](DECISIONS.md), [event types](docs/architecture/event-types.md), [organizer checklist](docs/runbooks/garage-sale.md)).
6. **Stage 5 — switching seasons (done in 0.11.0-beta.1; polished in 0.14.0-beta.1).**
   - An organizer flow to archive the current event and start the next one, possibly of another type.
   - Claims are kept; residents are invited, not re-registered.
   - A per-event export and retention clean-up of old listings.
   - Status: shipped in 0.11.0-beta.1 except inviting residents — Organizer → Events prepares a draft, previews it, puts it on the map in one step (the current event is archived; nothing carries over), ends an event, exports one event's listings and deletes an archived event's listings; residents keep their claims and list each home for the new event from an empty form ([D-20261031-12](DECISIONS.md), [-13](DECISIONS.md)). Emailing residents about a new event is not built yet.
   - 0.15.0-beta.1 replaced the switch by the life cycle of Stage 6 below (close an event, next year's is created).
   - 0.14.0-beta.1 finished the roadmap's polish: a release-blocking acceptance audit ([event-types-acceptance.md](docs/quality/event-types-acceptance.md)), every type checked at 375–1440 px in light and dark with larger text, more contrast and reduced motion, axe on every public page, keyboard-only use end to end, event-neutral copy outside the type modules, the organizer runbook ([organizer-events.md](docs/runbooks/organizer-events.md)) and commented reference configurations per type.

7. **Stage 6 — several events at once (done in 0.15.0-beta.1; the owner's decision of 2026-10-31).**
   - Events are draft, upcoming, open, closed or archived; at most one upcoming or open event per kind; one default event that "/" shows.
   - A switcher (tabs) between the public events; an upcoming event shows its dates (tentative, or to be announced), a closed one its thank-you message.
   - Closing an event creates next year's of the same kind (Halloween October 31, Christmas lights the same nights a year later, a sale to be announced).
   - Residents switch events in My house and may start next year's listing from their own details of last time.
   - Status: shipped in 0.15.0-beta.1 ([D-20261031-37](DECISIONS.md) to [-45](DECISIONS.md), [ADR 0011](docs/adr/0011-several-published-events.md), [event types](docs/architecture/event-types.md#several-events-at-once-015), [organizer guide](docs/runbooks/organizer-events.md)).

8. **Stage 7 — community branding and each event's branding (done in 0.16.0-beta.1; the owner's request of 2026-10-31).**
   - The community keeps its name, subtitle, city, logo, contact, links and an optional general About text; each event has its own emoji, headline, intro, colours and About text, each falling back to its type's look, edited under Organizer → Events.
   - A community's own Halloween branding moves to its Halloween events on the upgrade; Christmas lights and sales show their own look. The 0.9 image still shows the Halloween branding after an image-only rollback (the drill checks it).
   - Emails name what they concern (the sign-in link only the community); "What's new" shows plain notes for residents and organizers.
   - Status: shipped in 0.16.0-beta.1 ([D-20261031-46](DECISIONS.md) to [-51](DECISIONS.md), [ADR 0012](docs/adr/0012-community-and-event-branding.md), [event types](docs/architecture/event-types.md#community-branding-and-event-branding-016), [organizer guide](docs/runbooks/organizer-events.md#community-branding-and-each-events-branding)).

9. **Stage 8 — hidden next year's event (done in 0.17.0-beta.1; the owner's report of 2026-11-01).**
   - Closing an event creates next year's hidden: residents get ready in My house (list, or use last year's details) while visitors see nothing; the organizer announces it (upcoming), opens it directly or hides an announced one again, and may create an event hidden.
   - The thank-you card names and links next year's event only once it is announced; "Get my house ready" leads to My house.
   - Status: shipped in 0.17.0-beta.1 ([D-20261101-01](DECISIONS.md) to [-03](DECISIONS.md), [event types](docs/architecture/event-types.md), [organizer guide](docs/runbooks/organizer-events.md#hidden-residents-get-ready-visitors-see-nothing-yet)).

10. **Stage 9 — past events and the automatic close (done in 0.18.0-beta.1; the owner's request of 2026-11-04).**
   - Events close by themselves after their last day; closed events move from the switcher to Past events; the default follows the current event happening soonest, never next year's hidden one.
   - Each event may have its own access (a rummage sale open to the public, the lights not).
   - Status: shipped in 0.18.0-beta.1 ([D-20261104-01](DECISIONS.md) to [-06](DECISIONS.md), [event types](docs/architecture/event-types.md), [organizer guide](docs/runbooks/organizer-events.md#when-an-event-is-over)).

11. **Stage 10 — drawn art and logos (done in 0.19.0-beta.1; the owner's request of 2026-11-05).**
   - Every emoji the product drew is now its own drawn icon, mark or scene, and Christmas lights pages hang the C9 garland.
   - Each event may have a logo; logos live in the database, so backups carry them, and the 0.9 image still shows one after a rollback.
   - Status: shipped in 0.19.0-beta.1 ([D-20261105-01](DECISIONS.md) to [-07](DECISIONS.md), [ADR 0013](docs/adr/0013-drawn-art-and-logos-in-the-database.md)).

## What remains

These are known, deliberate gaps, each tracked here (there are no TODO notes in the code):

- **Inviting residents to a new event.** Organizer → Events switches the map; residents keep their claims and list again from an empty form, but they are told by hand (newsletter, group chat). An opt-in email through the existing mailer, with consent and the same limits as sign-in links, is the next step.
- **Retiring the legacy `listings` table.** It and its two triggers keep an image-only rollback to 0.9 working for one Halloween — the newest open or closed one (D-20261031-03, -06, -41). A later release drops them once no install needs that rollback, together with the community branding keys kept for 0.9 (D-20261031-49); the drill (`bin/drill-event-upgrade.sh`) proves the path until then.
- **Reminders per event.** An organizer's own reminders (Community & branding) still show for every event; each kind of event's built-in list applies only while they are empty. Moving them to each event, like its rules and branding, is the next step for them.
- **New homes for a hidden event.** A resident who has not claimed a home yet lists it from the map's public form, which lists for the event its page shows; for a hidden event they wait until it is announced. A "+ Add a home" form in My house would close this gap.
- **Browser tests on later dates.** The fictional demos' day-scoped statuses have fixed dates (December 12, 2026; April 10 and 11, 2027). Once a date has passed the API no longer reports that status, so `lights.spec` and `sale.spec` are expected to fail on later real dates. The server's date needs pinning on the test stacks ([test report](docs/quality/test-report.md)).
- **Access notes per event.** The community's access notes (gates, entrances, parking) stay the community's; an event with a different access leaves them out, and its rules carry what visitors need there.
- **Ordering the switcher's tabs.** Tabs are the default first, then by date; an organizer-chosen order is not built.
- **Per-night hours in the lights forms.** A lights home has one start and end time for all its nights in the forms; the server and the API already take hours per night.
- **44 px targets on Halloween.** Halloween's filter chips, time picker and card hearts stay 40 px high so its pages stay as they were; the other types have 44 px.
- **Contact check limits.** Numbers written as words are not caught, and a range starting at 200 or more ("500-1000") is refused as a phone number (D-20261031-33).

## Not planned

- Several communities in one install ([D-20261021-20](DECISIONS.md)); several events at once are built since 0.15 ([D-20261031-37](DECISIONS.md)), one current event of each kind.
- Organizer-defined custom form fields: they could not be validated or labelled accessibly.
- Photo uploads, comments, messaging between neighbors, payments or ticketing ([D-20261021-23](DECISIONS.md)).
- A native app: the site already works as a phone web app.
