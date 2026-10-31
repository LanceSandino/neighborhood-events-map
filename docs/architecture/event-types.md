# Event types

The map serves one community and one event at a time. What differs between a Halloween map, a Christmas lights map
and a garage sale map (listing fields, statuses, filters, icons, words) lives in an **event type**: a code module
selected by `event.type` in the community settings ([ADR 0010](../adr/0010-generic-event-types.md),
D-20261021-19, D-20261031-01). Claims, sign-in, abuse controls, moderation, routes and privacy are shared and never
ask which type is running.

Since 0.10 Halloween is the first (and so far only enabled) event type. Since 0.11 each event is a row of its own
(an *occurrence*) and listings belong to one occurrence: the next season, or another kind of event, starts with no
listings, and a resident lists their claimed home for it again from an empty form.

## The contract

| Side | Where | What it provides |
|---|---|---|
| PHP | `src/Event/EventType.php`, one final class per type in `src/Event/Type/` | key, default name and branding, copy (reminders, headings, access label, console labels), schedule kind and maximum days, hour choices, statuses (label, icon, day-scoped flag), display rules, feature switches, details defaults, `normalizeDetails()` (strict), `publicDetails()` (strict projection), `legacyFields()`, filters, legend, stats, route profiles, Twig fragments |
| Registry | `src/Event/EventTypeRegistry.php` | the types (tagged services) by immutable key; `KEYS` (reserved forever: `halloween`, `holiday_lights`, `garage_sale`), `ENABLED` (what organizers may pick), `problem()` for configuration errors |
| Twig | `templates/event/<key>/_*.html.twig`, `Twig/EventTypeExtension` | `event_type(key)` gives copy, statuses, filters, legend and hours; `event_fragment(key, name)` returns a template path only from the type's whitelist |
| Browser | `public/assets/js/events/<key>.js`, `events/index.js` | filter toggles and predicates, search words, tags, pin symbol and class, `statusOf`, `displayRules`, route note, copy, hour choices, the form helpers (`formDetails`, `fillForm`, `validateDetails`) |

Core code (`ListingDraft`, `ListingRepository`, `CommunityPresenter`, `CommunitySchema`, the controllers, `lib/filters.js`,
`lib/render.js`, `lib/time.js`, `home.js`, `register.js`, `me.js`, `route-panel.js`, the home, My house and confirmation
templates and the organizer's Community page) asks the type. There is no `if (type === …)` in core.

## Listing data

- **Input.** A listing has shared fields (`start`, `end`, `status`, `note`, `published`) and the type's details, sent
  as a `details` object. The type validates them strictly: unknown keys are refused with `details.<key>: Unknown field.`
  During the compatibility window the Halloween fields are also accepted flat, as 0.9 clients send them (D-20261031-02).
  The normalization of Halloween input is the 0.9 one, checked against a frozen copy of the 0.9 code
  (`tests/Unit/Listing/ListingDraftParityTest.php`).
- **Storage.** `listings.details` (JSONB, an object) holds the details and is what the app reads. The legacy Halloween
  columns (`candy` … `stroller_friendly`) are still written with the same values, and a trigger copies them into
  `details` on every insert and update, so the previous release keeps working after an image-only rollback and rows it
  writes are read correctly once the newer release runs again (D-20261031-03, `migrations/Version20261031000001.php`).
- **Public projection.** `publicDetails()` returns only whitelisted keys with typed values. In 0.10 a public home keeps
  exactly its 0.9 keys (the Halloween details flat); `event_type` and a `details` object join them with event-scoped
  listings (D-20261031-04).

## Events and event-scoped listings (0.11)

| Table | What it holds |
|---|---|
| `event_occurrences` | one event: immutable type key, public name, first and last local day, default hours, validated settings (`rules`, `route_modes`, `max_days`), state `draft` → `active` → `archived`; a partial unique index allows one active row |
| `event_listings` | one address's listing for one event (`UNIQUE (event_id, address_id)`): details, status and its `status_date`, note, publication, moderation; references `claims`, so release and erasure remove every event's listings |
| `event_listing_windows` | the listing's days: local date, start and end on that day (no overnight windows) |

- **Active event.** `CommunityRepository` loads the active occurrence into `CommunityConfig::event` when this release
  offers its type; the settings' `event` block is a view of it (`Event/OccurrenceSettingsSync` writes Community →
  Event saves to the occurrence: name and hours, dates only while nobody is listed). Without a usable active event the
  public page is a neutral "No event on the map right now", nothing is listed and registration is closed.
- **Reads and writes.** Every listing query names the active event: the public map, `/api/homes/{id}` (an address id
  resolves only within it), My house (`ResidentHomes`, owner-scoped per event), claims, the demo seed, organizer counts,
  the address list, the claims export and hide/unhide. Claims and the moderation hold stay address-scoped.
- **Schedule.** `Event/Schedule/ScheduleWindows` checks a listing's windows against its event for every schedule kind
  (`single_day`: the event's day with the home's hours; `nightly_range`: up to 62 days; `sale_days`: up to 3; the
  organizer's `max_days`; dates inside the event, once each; end after start; hour bounds).
- **Status.** `Event/Schedule/DisplayStatus` (PHP) and `lib/schedule.js` (browser) derive what visitors see from the
  stored status, `status_date`, the windows and "now" in the community time zone, by the type's `displayRules()`:
  a day-scoped status holds only on its own date, and "open" never shows outside a window. Both are tested on
  `tests/fixtures/schedule/display-status.json`. Halloween's display words are its 0.9 status words.
- **Public API.** A home keeps its 0.9 keys and adds `event_id`, `event_type`, `details`, `windows` and
  `display_status`; `/api/event` adds the occurrence's `id`, `active`, `starts_on`, `ends_on`, `default_hours`,
  `schedule_kind`, `filters`, `copy` and `rules`. Drafts and archived events are never public.
- **Organizers.** Organizer → Events (`Event/EventLifecycle`, `/api/admin/events`): drafts of offered types, an
  organizer-only preview, a one-step switch (the active event is archived under an advisory lock; the request names
  the event it replaces, so concurrent switches have one winner), archive, a CSV export and deleting an archived
  event's listings.
- **Browser.** Saved homes and routes are kept per event (`nhm:<community>:<event id>`); shared links carry
  `e=<event id>`. Route plans take a profile: walking by default, driving only when the type, the event and a provider
  allow it.
- **Rollback.** While the active event is a one-day Halloween event, the legacy `listings` table is kept equal to its
  listings in both directions (`Listing/LegacyListingBridge` and the `listings_bridge_to_events` trigger), so an older
  image still works on the same database: [event-types-migration.md](../runbooks/event-types-migration.md).

## Adding a type (later stages)

1. Implement `EventType` in `src/Event/Type/<Name>Type.php` (its key is already reserved in `EventTypeRegistry::KEYS`).
2. Add its fragments under `templates/event/<key>/` and its module `public/assets/js/events/<key>.js`, registered in
   `events/index.js`.
3. Add the key to `EventTypeRegistry::ENABLED` with tests: strict details, the public projection, fragments that
   exist, the module's filters and tags.
4. A fictional demo under `examples/` and a stack for the end-to-end tests.

Organizers choose among enabled types in the console (Community & branding → Event); they cannot define new fields.
