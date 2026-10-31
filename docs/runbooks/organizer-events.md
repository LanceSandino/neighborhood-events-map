# Running events through the year (organizers)

One install serves one neighborhood and shows **one event at a time**: a Halloween evening, a Christmas lights season
or a garage or rummage sale ([D-20261021-20](../../DECISIONS.md)). Residents keep their claimed homes from one event to
the next; each event has its own listings, and a home is listed for a new event from an empty form. Nothing from an
earlier event is ever copied or shown again ([D-20261031-05](../../DECISIONS.md), [-13](../../DECISIONS.md)).

Everything here happens in **Organizer → Events** (`/admin/events`). The checklists for each kind of event are
[christmas-lights.md](christmas-lights.md) and [garage-sale.md](garage-sale.md); Halloween night is
[event-day.md](event-day.md).

## The life of an event

| State | Who sees it | What you can do |
|---|---|---|
| **Draft** | organizers only | edit its name, dates, default hours and settings; preview it; put it on the map |
| **Active** (one at most) | everyone | residents list their homes; you edit its name, hours and settings, and its dates while nobody has listed (or move them explicitly on this page); end it |
| **Archived** | organizers only (export) | export its listings; delete its listings for retention |

Archived is final: an ended event never comes back on the map. Prepare a new one instead.

## Prepare the next event

1. **+ Prepare an event.** Choose the kind of event (Halloween, Christmas Lights, Garage Sale), its public name (for a
   sale, for example "Community Rummage Sale"), the day or days, the hours most homes keep and up to eight short rules
   (parking, one-way streets, quiet hours…). The kind of event decides the rest:
   - Halloween: one evening.
   - Christmas lights: up to 62 nights, an optional best night for a neighborhood walk, optional quiet hours, an
     optional drive-by tour.
   - Garage sale: one to three sale days inside the span, the item categories residents may list (the map offers
     filters only for those), an optional early-bird time, an optional driving tour.
   A driving tour needs a routing provider as well; without one, saved stops stay an ordered list with no lines.
2. **Preview** the draft. The preview is the public map with the draft's look, words, filters and dates and no homes;
   only organizers can open it, and nothing about a draft is public (not in `/api/event`, not on any page).
3. Adjust and preview again as often as you like. Community branding that you never changed follows the kind of
   event (the lights' midnight blue and gold, the sale's teal and coral); branding you set yourself stays
   ([D-20261031-16](../../DECISIONS.md)).

## Put it on the map

**Put on the map** switches in one step: the event on the map is archived and the draft becomes active, under a lock,
so two organizers switching at the same moment cannot both succeed (the second gets "changed" and reloads). At once:

- last event's homes leave the map, also by direct link, and nothing is republished;
- residents still manage their claimed homes; My house shows **List for <event>** for each one, starting from an
  empty form;
- visitors' saved stops belong to each event, and a shared link made for the old event opens without its stops.

**Residents are not emailed automatically.** Inviting them to list for the new event is manual for now: tell them
through your usual neighborhood channels (newsletter, group chat, flyer). Emailing residents from the app is planned
work ([ROADMAP](../../ROADMAP.md)).

## End an event without a next one

**End event** archives the active event without activating another. The public map then says "No event on the
map right now", listing is closed and no homes are shown. Prepare and activate a draft when you are ready.

## Export and retention

- **Export CSV** gives one event's listings: address, zone, status, days and hours, details and
  note. It never contains owners' emails. It is a POST with the console's CSRF token and is audited.
- **Delete listings** removes an archived event's listings for good (retention), after you type DELETE. Claims are
  never deleted by it, and other events' listings are untouched. Keep an export first if your neighborhood wants a
  record.
- A resident who gives up a home, an organizer's **Unclaim** and an account deletion (`app:user:delete`) remove that
  address's listings of every event, archived ones included. A **Transfer** moves the claim with its listings to the
  new owner.

## Seasonal archiving (legacy rows)

Event-scoped listings need no archive run: only the active event's listings are ever public. `app:season:archive`
remains for the legacy `listings` table that the previous release reads after an image-only rollback: it unpublishes
legacy rows of days before the active event. Run it after a season if you ever rolled back to 0.10 or older.

## Backups and upgrades

- Backups run daily (`backup` service, 14 days kept); take one by hand before any switch or upgrade:
  `bin/backup-now.sh`, and copy it off the host. Restore with `bin/restore.sh` ([backup-restore.md](backup-restore.md)).
- Upgrading from 0.9 or 0.10 runs every migration in order on start. What changes, how to check it and both ways back
  (image only, or the pre-upgrade backup) are in [event-types-migration.md](event-types-migration.md); the release
  procedure ([release.md](release.md)) runs the drill on the images first.

## Command-line tasks (`docker compose exec app php bin/console …`)

| Command | What it does |
|---|---|
| `app:admin:grant <email>` (`--revoke`) | grant or revoke the organizer role |
| `app:community:bootstrap <file>` (`--force`) | import `community.yaml` and its GeoJSON into an empty install (the first event comes from its `event` block and `event.settings`) |
| `app:community:export <dir>` | export the settings (YAML) and the boundary and zones (GeoJSON) |
| `app:addresses:import <file.csv>` (`--dry-run`) | import a reviewed address list (address, latitude, longitude, zone_key) |
| `app:zone:label` | pin a zone's name to a point on the map |
| `app:user:delete <email>` (`--confirm`) | delete an account with its claims and every event's listings (privacy requests) |
| `app:user:sign-out <email>` | sign an account out on every device |
| `app:season:archive` | unpublish legacy rows of earlier days (see above) |
| `app:maintenance:purge` | delete expired links, proof-of-work challenges, claim intents, throttle events, sessions and the route cache (the `maintenance` service runs it) |
| `app:ledger:export` / `app:ledger:replay` | the account ledger that `bin/restore.sh` saves and re-applies |
| `app:demo:seed` | fictional demo homes (`APP_DEMO=1` only) |
| `app:db:ping` | check the database connection |

Reference configurations for each kind of event: [config/community.example.yaml](../../config/community.example.yaml)
(Halloween), [examples/reference/holiday-lights.community.yaml](../../examples/reference/holiday-lights.community.yaml)
and [examples/reference/garage-sale.community.yaml](../../examples/reference/garage-sale.community.yaml).
