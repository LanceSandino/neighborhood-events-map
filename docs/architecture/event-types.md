# Event types

The map serves one community and one event at a time. What differs between a Halloween map, a Christmas lights map
and a garage sale map (listing fields, statuses, filters, icons, words) lives in an **event type**: a code module
selected by `event.type` in the community settings ([ADR 0010](../adr/0010-generic-event-types.md),
D-20261021-19, D-20261031-01). Claims, sign-in, abuse controls, moderation, routes and privacy are shared and never
ask which type is running.

Since 0.10 Halloween is the first event type. Since 0.11 each event is a row of its own (an *occurrence*) and listings
belong to one occurrence: the next season, or another kind of event, starts with no listings, and a resident lists
their claimed home for it again from an empty form. Since 0.12 **Christmas lights** (`holiday_lights`) is the second
enabled type ([below](#christmas-lights-012); organizer checklist: [christmas-lights.md](../runbooks/christmas-lights.md)),
and since 0.13 the **garage sale** (`garage_sale`) the third ([below](#garage-sale-013); organizer checklist:
[garage-sale.md](../runbooks/garage-sale.md)). Every reserved key is enabled now (D-20261031-31).

## The contract

| Side | Where | What it provides |
|---|---|---|
| PHP | `src/Event/EventType.php`, one final class per type in `src/Event/Type/` | key, default name and branding (optionally its colours), copy (reminders, headings, access label, console labels), schedule kind and maximum days, hour choices, statuses (label, icon, day-scoped flag), display rules, feature switches, details defaults, `normalizeDetails()` (strict), `publicDetails()` (strict projection), `legacyFields()`, filters, legend, stats, route profiles, Twig fragments; optionally event settings of its own (`Event/EventSettingsType`: keys, checks against the event's dates, `finishSettings()` — completed and checked as a whole —, `settingOptions()` for the console, `listingDates()` — the only days a listing may choose —, public part) and a check of resident text (`Event/ListingTextCheck`: the sale's contact-detail check on the note) |
| Registry | `src/Event/EventTypeRegistry.php` | the types (tagged services) by immutable key; `KEYS` (reserved forever: `halloween`, `holiday_lights`, `garage_sale`), `ENABLED` (what organizers may pick), `problem()` for configuration errors |
| Twig | `templates/event/<key>/_*.html.twig`, `Twig/EventTypeExtension` | `event_type(key)` gives copy, statuses, filters, legend and hours; `event_fragment(key, name)` returns a template path only from the type's whitelist. Every type provides every fragment the core includes; some may render nothing: `head` (the type's stylesheet), `hero_art`, `schedule_row`, `filter_controls` (Halloween's "Visit at", the sale's "Open <weekday>"), card symbol and tags, confirmation tags, `form_hours` and `manage_hours` (the shared start/end fields; a sale has none), form fields, My house fields, listing checks, `route_rules`, `rule_lines` (a type's own rule lines on About and next to the route) |
| Browser | `public/assets/js/events/<key>.js`, `events/index.js` | filter toggles and predicates (with the moment, `matches(home, filters, ctx)`), optional filter `choices` (lights: a night and a style), search words, tags, pin symbol and class, `inactive` pins, `statusOf`, `statusChipShown`, `hoursLabel`, `displayRules`, `isOpen(home, ctx)`, route note, copy, hour choices, an optional `mapColor`, the form helpers (`initForm`, `formDetails`, `formExtras` — e.g. the nights as windows —, `fillForm`, `validateDetails`) |
| Theme | `<html data-event-type>`, `public/assets/css/events/common.css`, `public/assets/css/events/<key>.css` | the components every type with several days shares (season row and pill, route mode and rules, 44 px targets; D-20261031-30), then the type's token overrides in light and dark (identical dark blocks) and its own components, loaded by its `head` fragment; never the organizer's brand variables, so /theme.css still wins (D-20261031-20). Halloween loads none |

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

## Christmas lights (0.12)

| Part | Christmas lights (`HolidayLightsType`, `events/holiday_lights.js`, `templates/event/holiday_lights/`) |
|---|---|
| Schedule | `nightly_range`, up to 62 nights; a listing names its nights as windows (the forms: every night or the nights picked, with one start and end) |
| Details (strict) | `styles` (≥ 1 of classic, animated, synchronized_music, inflatables, walkthrough), `flashing_warning`, `music_enabled`, `fm_frequency` (one decimal from 87.5 to 108.0, only with music, never free text), `viewing_mode` (`drive_by_only` default, `walk_up_welcome`), `treats`, `cocoa`; the note is the shared listing note (D-20261031-18) |
| Statuses | `active`, `off_tonight` (day-scoped: only on the local date it was set), `finished`; display keys `lights_on`, `later_tonight`, `not_tonight`, `off_tonight`, `finished` |
| Public home | the shared keys (no legacy flat fields), the event keys and `status_date` (a day-scoped status's date while it is today or later; D-20261031-17) |
| Public event | its `best_night` and `quiet_hours`, and `route_modes` (driving only with the type, the event's `route_modes` and a provider; D-20261031-22) |
| Filters | quick: Drive-by, No flashing reported, Music (+ Saved); more: Walk-up welcome, Open tonight, one night (date), a display style |
| Words | hero "Holiday nights. / Bright lights.", "Putting up lights?", "Your lights tour", viewing etiquette; "No flashing reported", never "safe" |
| Look | midnight blue `#38597F`, warm gold `#CE9D3D`, calm cream; navy dark mode; non-flashing icons (✨ 🌟 🎵 ⛄ 🎄 🚗 🚶 🕯️ ⚡), nothing animates |
| Event settings | `best_night` (inside the event), `quiet_hours` (≤ 120 characters), plus the shared `rules`, `route_modes`, `max_days` |

Branding that still holds a type's built-in default follows the type of the event on the map, and the draft preview
uses the draft's type (Community/TypeDefaults, D-20261031-16). The fictional demo is `examples/demo-lights/` (ten homes,
Dec 1, 2026 – Jan 3, 2027; the dev stack `.env.lights` on port 8089).

## Garage sale (0.13)

| Part | Garage sale (`GarageSaleType`, `events/garage_sale.js`, `templates/event/garage_sale/`) |
|---|---|
| Schedule | `sale_days`, one to three days chosen by the organizer (event setting `days`; a span of up to three days takes all its days); a listing names its days as windows, each with its own hours (Saturday and Sunday may differ); the forms have no shared start and end (D-20261031-26) |
| Details (strict) | `categories` (≥ 1 of kids_baby, toys, furniture, clothing, tools, books_media, electronics, sports, household, free; only those the event allows), `multi_family`, `early_birds_allowed`, `payment_types` (cash, card, apps — the kind only); the note is the shared listing note (D-20261031-24) |
| Note check | `Shared/ContactDetails` through `Event/ListingTextCheck`: a note with a phone number, email, payment handle or web address fails the whole listing with a field error before anything is written — no claim request, no listing, no email (D-20261031-25) |
| Statuses | `active`, `paused` and `sold_out` (day-scoped, through `status_date` like the lights' off tonight), `closed`; display keys `open`, `opens_at`, `not_today`, `paused`, `sold_out`, `closed` — sold out never shows as open; a paused, sold-out or closed pin is greyed on a sale day |
| Public home | the shared keys (no legacy flat fields), the event keys and `status_date` (D-20261031-17) |
| Public event | `days`, `categories` (allowed), `early_bird_time`, and `route_modes` (driving only with the type, the event's `route_modes` and a provider) |
| Filters | quick: Kids & toys, Furniture, Tools (+ Saved); more: Household, Electronics, Free items, Open now, Open <weekday> per sale day (`day1`–`day3`, drawn by `filter_controls`), Multi-family, Early birds welcome; search over streets, categories, payment kinds and notes (D-20261031-27) |
| Words | hero "Good finds. / Close to home.", the sale row ("Sat–Sun, April 10–11, 2027", "Most homes: 8 AM–2 PM · Individual hours vary", the early-bird time), the pill "Coming this <season>" / "Today" / "More sale days ahead" / "Sale over" (D-20261031-28), "Having a sale?", "Your sale stops", sale etiquette (early birds, parking and traffic, driveways and private yards, no recalled or prohibited items, the map sells nothing) |
| Look | teal `#178D80`, coral `#E77C5D`, soft mint, off-white; slate-teal dark mode; category icons with words (🍼 🧸 🪑 👕 🛠️ 📚 🔌 ⚽ 🧺 🆓); teal buttons carry near-black text for AA (D-20261031-32) |
| Event settings | `days`, `categories`, `early_bird_time`, plus the shared `rules`, `route_modes`, `max_days`; a sale day homes chose cannot be removed (409 `listings_days`) |

The public name is the event's name (default "Garage Sale", e.g. "Community Rummage Sale"). `community.yaml` may set the
first event's settings (`event.settings`, D-20261031-29). The fictional demo is `examples/demo-sale/` (ten homes, Sat–Sun,
April 10–11, 2027; the dev stack `.env.sale` on port 8090).

## Adding a type (later stages)

1. Reserve its key in `EventTypeRegistry::KEYS` and in the `event_occurrences` type check (a migration), implement
   `EventType` in `src/Event/Type/<Name>Type.php`, `EventSettingsType` if its events have settings of their own and
   `ListingTextCheck` if resident text needs a check of its own.
2. Add its fragments under `templates/event/<key>/` (all of them; a fragment may render nothing), its module
   `public/assets/js/events/<key>.js`, registered in `events/index.js`, and optionally its stylesheet
   `public/assets/css/events/<key>.css`.
3. Add the key to `EventTypeRegistry::ENABLED` with tests: strict details, the public projection, fragments that
   exist, the module's filters and tags.
4. A fictional demo under `examples/` and a stack for the end-to-end tests.

Organizers choose among enabled types under Organizer → Events; they cannot define new fields.
