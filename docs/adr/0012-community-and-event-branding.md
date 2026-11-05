# ADR 0012 — Community branding and each event's own branding

**Status.** Accepted (owner request, 2026-10-31). Decisions D-20261031-46 to -52. Supersedes the branding clause of
D-20261031-16. Refined by ADR 0013: drawn marks replace the emoji, and each event may have its own logo.

**Context.** Until 0.15 the community kept one branding for every event: emoji, headline and its accent part, intro, the
two brand colours and the About text. A value that still held a type's built-in default followed the event's type
(D-20261031-16), but anything an organizer changed showed everywhere. Testing a copy of a real site with Halloween,
Christmas lights and a yard sale open at once, the owner found the community's own Halloween branding — a pumpkin emoji,
a ghost headline, a trick-or-treat intro and About text, Halloween's colours — on the lights and the sale pages, and asked for one branding for the community and a separate
one for each event. The sign-in email also named the
default event, and the public "What's new" had grown operator notes. Production runs 0.9.0-beta.1 with its own custom
Halloween branding and upgrades straight to this release; an image-only rollback to 0.9 must keep working.

**Decision.**
- **Community branding** (every event): name, subtitle, city line, logo, contact, links and an optional general About
  text for the neighborhood (`community.general_about`). Nothing event-specific.
- **Event branding** (each event's `settings.branding`, under Organizer → Events): emoji, headline, accent part, intro,
  the two colours and the event's About text. Every field is optional and falls back to the event type's default; hero
  decoration comes from the type only. Checks are strict; BrandPalette keeps text readable on each event's colours.
- **About text** of an event's page: its own, else the community's general text, else the type's built-in text.
- **Upgrade**: a data-only migration copies the community's custom values (not equal to any built-in default) and its
  About text into every Halloween event; lights and sales show their type's look. `community.yaml` keeps its shape: the
  top-level branding brands the install's first event, and `event.settings.branding` may name fields explicitly.
- **0.9 rollback**: until the legacy bridge is retired, the community settings keep the old keys under their old names,
  written from the Halloween the 0.9 image shows on every settings save and event change; this release never shows
  them for an event. The drill checks the 0.9 page shows the custom Halloween headline after an image rollback.
- **Emails**: the sign-in link names the community only; a claim link names the event of the listing and its address;
  a review request the event of the page it came from.
- **What's new** shows only plain notes for residents and organizers; operator notes live in the upgrade runbook and
  CHANGES.md.
- **Organizer console** (D-20261031-52): the console is the community's — its name, its logo or initial, a neutral icon
  and the console's own neutral colours on every page. An event's branding and words appear only where that event is in
  focus: its row and form in Events, its preview (with its own colours), the Overview's list of events, the Addresses
  listing column and audit rows about it.

**Consequences.** A community that never changed its branding looks the same as before for every type. A community with
its own Halloween look keeps it on Halloween — next year's Halloween keeps it too — and its other events show their own
types' looks until an organizer gives them one. The community console shows fewer fields and no longer edits the
default event (Events does). The stored community keys are a mirror for an older release only; a change made there on
0.9 is replaced by the events' own once this release runs again. Organizer-written reminders stay community-wide.

**Alternatives considered.**
- Keep one community branding and only stop showing it on other types: a community could not brand its Christmas lights
  without its Halloween changing too.
- Move the community keys out entirely: the 0.9 image reads them; after an image rollback it would lose the Halloween
  branding (and its theme stylesheet needs the colours).
- A new storage column or table for the branding: more schema for six short values per event that already have a
  validated settings object.
- Event branding as a full copy of the type's defaults: the defaults would freeze into every event; storing only what an
  organizer changed keeps "empty means the type's default".
