# ADR 0011 — Several published events per community

**Status.** Accepted (owner decision, confirmed on 2026-10-31). Decisions D-20261031-37 to -45. Supersedes the
one-active-event part of [ADR 0010](0010-generic-event-types.md) and of D-20261021-20.

**Amended in 0.17** (D-20261101-01, -02): a `hidden` state between draft and upcoming. Closing creates next year's
event hidden — residents get ready in My house, visitors see nothing until the organizer announces it — and counts as
the current event of its type. The rest of this record is unchanged.

**Amended in 0.18** (D-20261104-01 to -05): events close by themselves after their last day, through the same Close. The
map's switcher shows only current events (open and upcoming); closed events are listed under Past events, and My house
keeps them as read-only tabs. When the default stops being current it moves to the current event happening soonest, and a
closed event can no longer be chosen as the default. An event may have its own access.

**Context.** Since 0.11 every event is a row of its own and listings belong to one event, but only one event could be
on the map: an organizer switched seasons by archiving the event on the map. Neighborhoods run their events side by
side — the Christmas lights season starts while Halloween's thank-you is still up, and a rummage sale is announced
months ahead — and residents want to get their house ready for next year while this year's event is still showing.
The owner decided on 2026-10-31 that several events may be on at the same time, that visitors switch between them,
that the organizer picks the default, that an event is closed when it is over (with a thank-you message) and that
closing creates next year's event. One community per install stays.

**Decision.**
- Life cycle: draft (organizers only) → upcoming (residents may list; visitors see the dates) → open (the public map
  with homes) → closed (the thank-you message, no homes) → archived (hidden; listings kept until purged). A closed event
  never reopens; next year is a new event. Archived is final.
- At most one current (upcoming or open) event per type, by a partial unique index; drafts may coexist (organizer-only)
  but become current only while no other event of their type is. One default event, by a partial unique index, and only
  an upcoming, open or closed event may be it; when the default is archived the newest open event, else the newest
  public one, takes its place.
- Closing is one transaction under the occurrences lock: the event closes and next year's event of the same type is
  created as upcoming with tentative dates — the type decides them (Halloween October 31; Christmas lights the same
  nights a year later; a garage sale's dates to be announced). Dates to be announced are both dates null; such an event,
  or a sale without days, cannot open; opening confirms tentative dates.
- `?e=<event id>` selects the event on every public page and API, My house and the resident's writes; without it the
  default event. Unknown, draft and archived ids are 404 for the API and the default event for a page. The switcher is a
  nav of links shown while two or more events are public. GET /api/events lists the public events.
- Residents: My house per event; "Use last year's details" copies their own listing of the same home from the previous
  event of the same type into an empty form; a closed event's listings are read-only. A claim stays address-scoped.
- The 0.9 rollback bridge mirrors one Halloween: the newest open or closed one.

**Consequences.** Every request now carries an event (the selected or the default one), and core code still asks
`CommunityConfig::event` — the only change is which event that is, so the type modules and most services are unchanged.
Visitors see an event's state: homes only for an open event. A single-event install looks exactly as before (no
switcher), so Halloween's pages and API answers keep their parity. The migration is additive (active becomes open and
the default; no row is deleted). After an image-only rollback the 0.9 release shows only the mirrored Halloween; the
0.10–0.14 releases do not know the new states and are reached through a restore instead. Moderation holds stay
address-scoped and apply to every current event's listing.

**Alternatives considered.**
- Keep one active event and switch seasons (0.11–0.14): residents could not prepare next year's listing while this
  year's thank-you showed, and a neighborhood could not show the lights and announce a sale at once.
- Several communities or a calendar of many events per type: out of scope; one current event per type keeps the map,
  the bridge and "last year's details" unambiguous.
- A tablist switcher with client-side panels: each event has its own theme, filters and data, so each tab is its own
  server-rendered page; links with aria-current describe that.
- Including drafts in the one-current-per-type index: it would make the 0.11 backfill's drafts collide with the open
  event during the migration, and drafts are not visible to anyone but organizers.
