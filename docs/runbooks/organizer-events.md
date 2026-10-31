# Running events through the year (organizers)

One install serves one neighborhood. Since 0.15 it can show **several events at once** — a Halloween evening, a
Christmas lights season, a garage or rummage sale — and visitors switch between them with the tabs at the top of the
page; "/" shows the **default** event, which you choose ([ADR 0011](../adr/0011-several-published-events.md),
[D-20261031-37](../../DECISIONS.md)). There is at most one current (upcoming or open) event of each kind. Residents keep
their claimed homes from one event to the next; each event has its own listings, and a home is listed for a new event
from an empty form — or, if the resident chooses, from their own details of last time ("Use last year's details",
[D-20261031-42](../../DECISIONS.md)). Nothing is copied or shown again by itself.

Everything here happens in **Organizer → Events** (`/admin/events`). The checklists for each kind of event are
[christmas-lights.md](christmas-lights.md) and [garage-sale.md](garage-sale.md); Halloween night is
[event-day.md](event-day.md).

## The life of an event

| State | Who sees it | What you can do |
|---|---|---|
| **Draft** | organizers only (preview) | edit its name, dates (or "to be announced"), hours, settings and thank-you message; preview it; announce it; open it |
| **Upcoming** | everyone: a tab with its dates ("tentative" or "dates to be announced"), no homes | residents list their homes once it has dates; you edit it, open it, make it the default |
| **Open** | everyone: the map with its homes | residents list and update their homes; you edit its name, hours and settings (its dates while nobody has listed, or move them explicitly), make it the default, close it |
| **Closed** | everyone: a tab with the thank-you message, no homes | edit its name and thank-you message; make it the default (for example right after the event); archive it. Its listings are read-only |
| **Archived** | organizers only (export) | export its listings; delete its listings for retention |

A closed event never opens again, and archived is final: next year's event is a new event (closing creates it for you).

## Prepare an event

1. **+ Prepare an event.** Choose the kind of event (Halloween, Christmas Lights, Garage Sale). The dates start on the
   usual ones — Halloween October 31, Christmas lights November 1 to January 1, a sale "dates to be announced" — and
   you can change them, mark them **tentative** or leave them **to be announced**. Then the public name (for a sale, for
   example "Community Rummage Sale"), the hours most homes keep, up to eight short rules (parking, one-way streets,
   quiet hours…) and, if you like, the thank-you message visitors see once it is closed (leave it empty for the
   default, which names next year's dates). The kind of event decides the rest:
   - Halloween: one evening.
   - Christmas lights: up to 62 nights, an optional best night for a neighborhood walk, optional quiet hours, an
     optional drive-by tour.
   - Garage sale: one to three sale days inside the span, the item categories residents may list (the map offers
     filters only for those), an optional early-bird time, an optional driving tour.
   A driving tour needs a routing provider as well; without one, saved stops stay an ordered list with no lines.
2. **Preview** the draft. The preview is the public map with the draft's look, words, filters and dates and no homes;
   only organizers can open it, and nothing about a draft is public (not in `/api/events`, not on any page).
3. **Announce** it when residents may start listing (it becomes upcoming: a tab with its dates, no homes yet), or
   **Open** it to put it on the map right away. Opening needs dates (a sale also its days) and confirms tentative dates.
   Only one event of each kind can be upcoming or open: close or archive the current one first.

Community branding that you never changed follows the kind of event shown (the lights' midnight blue and gold, the
sale's teal and coral); branding you set yourself stays ([D-20261031-16](../../DECISIONS.md)).

## Close an event when it is over

**Close** asks for confirmation and shows the thank-you text visitors will see and next year's dates it will create.
In one step:

- the event's homes leave the map (also by direct link) and its tab shows the thank-you message, for example "Halloween
  2026 was a success — thank you! Come back next year (Oct 31, 2027, tentative). Residents: sign in to get your house
  ready for next year.";
- next year's event of the same kind is created as **upcoming**: Halloween on October 31 of next year, Christmas
  lights on the same nights a year later, a sale with its dates to be announced — with the same name, hours, rules and
  lasting settings (never the best night, the sale days or the thank-you text);
- residents see next year's tab in My house and can list their homes for it before it opens; nothing is public until
  you open it.

Two organizers closing at the same moment cannot both succeed (the second gets "changed" or "not open" and reloads).

## The default event

**Make default** on any upcoming, open or closed event: "/" shows it, and Community → Event edits it. A typical year:
Halloween open and default in October; closed (still default, showing its thank-you) on November 1; the Christmas lights
opened and made default in December; the sale announced months ahead. If you archive the default event, the newest
open event (else the newest public one) becomes the default.

**Residents are not emailed automatically.** Inviting them to list for an event is manual for now: tell them through
your usual neighborhood channels (newsletter, group chat, flyer). Emailing residents from the app is planned work
([ROADMAP](../../ROADMAP.md)).

## No event at all

If you archive every public event, the public site says "No event on the map right now", listing is closed and no
homes are shown. Announce or open an event when you are ready.

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

Event-scoped listings need no archive run: only open events' listings are ever public. `app:season:archive` remains for
the legacy `listings` table that the 0.9 release reads after an image-only rollback: it unpublishes legacy rows of days
before the Halloween that table mirrors (the newest open or closed one). Run it after a season if you ever rolled back
to 0.9.

## Backups and upgrades

- Backups run daily (`backup` service, 14 days kept); take one by hand before any switch or upgrade:
  `bin/backup-now.sh`, and copy it off the host. Restore with `bin/restore.sh` ([backup-restore.md](backup-restore.md)).
- Upgrading from 0.9 (or any later release) runs every migration in order on start. What changes, how to check it and both ways back
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
