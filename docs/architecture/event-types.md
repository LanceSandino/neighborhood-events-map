# Event types

The map serves one community and one event at a time. What differs between a Halloween map, a Christmas lights map
and a garage sale map (listing fields, statuses, filters, icons, words) lives in an **event type**: a code module
selected by `event.type` in the community settings ([ADR 0010](../adr/0010-generic-event-types.md),
D-20261021-19, D-20261031-01). Claims, sign-in, abuse controls, moderation, routes and privacy are shared and never
ask which type is running.

Since 0.10 Halloween is the first (and so far only enabled) event type. Nothing visible changed: the public API, the
pages and the screenshots are the 0.9 ones, plus `event.type` in `GET /api/event`.

## The contract

| Side | Where | What it provides |
|---|---|---|
| PHP | `src/Event/EventType.php`, one final class per type in `src/Event/Type/` | key, default name and branding, copy (reminders, headings, access label, console labels), schedule kind and maximum days, hour choices, statuses (label, icon, day-scoped flag), feature switches, details defaults, `normalizeDetails()` (strict), `publicDetails()` (strict projection), `legacyFields()`, filters, legend, stats, route profiles, Twig fragments |
| Registry | `src/Event/EventTypeRegistry.php` | the types (tagged services) by immutable key; `KEYS` (reserved forever: `halloween`, `holiday_lights`, `garage_sale`), `ENABLED` (what organizers may pick), `problem()` for configuration errors |
| Twig | `templates/event/<key>/_*.html.twig`, `Twig/EventTypeExtension` | `event_type(key)` gives copy, statuses, filters, legend and hours; `event_fragment(key, name)` returns a template path only from the type's whitelist |
| Browser | `public/assets/js/events/<key>.js`, `events/index.js` | filter toggles and predicates, search words, tags, pin symbol and class, `statusOf`, route note, copy, hour choices, the form helpers (`formDetails`, `fillForm`, `validateDetails`) |

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

## Adding a type (later stages)

1. Implement `EventType` in `src/Event/Type/<Name>Type.php` (its key is already reserved in `EventTypeRegistry::KEYS`).
2. Add its fragments under `templates/event/<key>/` and its module `public/assets/js/events/<key>.js`, registered in
   `events/index.js`.
3. Add the key to `EventTypeRegistry::ENABLED` with tests: strict details, the public projection, fragments that
   exist, the module's filters and tags.
4. A fictional demo under `examples/` and a stack for the end-to-end tests.

Organizers choose among enabled types in the console (Community & branding → Event); they cannot define new fields.
