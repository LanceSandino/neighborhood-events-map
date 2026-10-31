# Roadmap

Neighborhood events map started as a Halloween trick-or-treat map. The next two event types are a **Christmas lights map** and a **garage sale map**. Both are *generic* event types: any neighborhood can pick one in its settings, and nothing about a particular neighborhood goes into code ([D-20261018-01](DECISIONS.md)).

This is a plan, not a promise of dates. Each stage ships as its own minor pre-release once it passes every quality gate ([D-20261019-18](DECISIONS.md)). The architectural choices below are recorded in [DECISIONS.md](DECISIONS.md) as D-20261021-19 to D-20261021-23 and in [ADR 0010](docs/adr/0010-generic-event-types.md).

## What stays the same for every event type

The parts that make the Halloween map safe are shared by every event type, unchanged:

- **One community per install, one active event at a time.** A neighborhood that runs a lights map in December and a garage sale in May switches the event between seasons.
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
3. **Stage 2 — multi-day events.**
   - Add date ranges and daily hours to the event ([D-20261021-22](DECISIONS.md)).
   - A listing's own subset of days and hours.
   - “Open now” and “open on <day>” in the community time zone.
   - The season archive and the “last season's homes disappear” rule generalized to ranges.
   - Status: the core shipped in 0.11.0-beta.1 — events are rows of their own with a date range and default hours, listings belong to one event and have their own days and hours (windows), the schedule core checks windows for one-day, nightly and sale-day kinds, and the display status (“open now”, “later”, a status that holds for one day) is derived in the community time zone by the same rules in the server and the browser. Halloween stays a one-day event; the date picker and “open on <day>” filters come with the types that need them ([D-20261031-05](DECISIONS.md) to [-11](DECISIONS.md), [event types](docs/architecture/event-types.md)).
4. **Stage 3 — Christmas lights.**
   - The `holiday_lights` type: fields, filters, icons, statuses, copy and reminders.
   - The drive-by tour through a provider's driving profile, only when the organizer enables a provider.
   - Demo data: a fictional lights community under `examples/`.
5. **Stage 4 — garage sale.**
   - The `garage_sale` type: categories, days and hours, statuses, moderation rules and the server-side contact-detail check on notes.
   - Demo data: a fictional sale under `examples/`.
6. **Stage 5 — switching seasons.**
   - An organizer flow to archive the current event and start the next one, possibly of another type.
   - Claims are kept; residents are invited, not re-registered.
   - A per-event export and retention clean-up of old listings.
   - Status: shipped in 0.11.0-beta.1 except inviting residents — Organizer → Events prepares a draft, previews it, puts it on the map in one step (the current event is archived; nothing carries over), ends an event, exports one event's listings and deletes an archived event's listings; residents keep their claims and list each home for the new event from an empty form ([D-20261031-12](DECISIONS.md), [-13](DECISIONS.md)). Emailing residents about a new event is not built yet.

## Not planned

- Several communities in one install, or several events running at once ([D-20261021-20](DECISIONS.md)).
- Organizer-defined custom form fields: they could not be validated or labelled accessibly.
- Photo uploads, comments, messaging between neighbors, payments or ticketing ([D-20261021-23](DECISIONS.md)).
- A native app: the site already works as a phone web app.
