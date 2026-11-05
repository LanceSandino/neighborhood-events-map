# Running events through the year (organizers)

One install serves one neighborhood. Since 0.15 it can show **several events at once** — a Halloween evening, a
Christmas lights season, a garage or rummage sale — and visitors switch between them with the tabs at the top of the
page; "/" shows the **default** event, which you choose ([ADR 0011](../adr/0011-several-published-events.md),
[D-20261031-37](../../DECISIONS.md)). There is at most one current (hidden, upcoming or open) event of each kind. Residents keep
their claimed homes from one event to the next; each event has its own listings, and a home is listed for a new event
from an empty form — or, if the resident chooses, from their own details of last time ("Use last year's details",
[D-20261031-42](../../DECISIONS.md)). Nothing is copied or shown again by itself.

Everything here happens in **Organizer → Events** (`/admin/events`), which groups your events: **On the site now**,
**Hidden, getting ready**, **Drafts**, **Past events** and **Archived**. The checklists for each kind of event are
[christmas-lights.md](christmas-lights.md) and [garage-sale.md](garage-sale.md); Halloween night is
[event-day.md](event-day.md).

## The life of an event

| State | Who sees it | What you can do |
|---|---|---|
| **Draft** | organizers only (preview) | edit its name, dates (or "to be announced"), hours, settings and thank-you message; preview it; let residents get ready (hidden); announce it; open it |
| **Hidden · residents can get ready** | residents in My house and organizers (preview); visitors see nothing — no tab, no page | residents list their homes (or use last year's details) once it has dates; you edit it, preview it, announce it or open it. Closing an event creates next year's in this state ([D-20261101-01](../../DECISIONS.md)) |
| **Upcoming** | everyone: a tab with its dates ("tentative" or "dates to be announced"), no homes | residents list their homes once it has dates; you edit it, open it, make it the default, or hide it again |
| **Open** | everyone: the map with its homes | residents list and update their homes; you edit its name, hours and settings (its dates while nobody has listed, or move them explicitly), make it the default, close it |
| **Closed** | everyone: under **Past events**, with the thank-you message, no homes | edit its name and thank-you message; archive it. Its listings are read-only; residents still read them from its tab in My house |
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
3. **Let residents get ready** (hidden) when residents may start listing but visitors should not see it yet,
   **Announce** it when visitors may see it too (it becomes upcoming: a tab with its dates, no homes yet), or **Open**
   it to put it on the map right away. Opening needs dates (a sale also its days) and confirms tentative dates. Only
   one event of each kind can be hidden, upcoming or open: close or archive the current one first. You can also
   choose **Start as: Hidden — residents can get ready** when you prepare it.

## Hidden: residents get ready, visitors see nothing yet

A hidden event is for getting ready ([D-20261101-01](../../DECISIONS.md)):

- visitors do not see it anywhere: no tab, no page, nothing in the public lists, and a link to it shows the default
  event instead;
- signed-in residents see it in **My house** (its tab says "Getting ready"), list their homes for it and can use
  "Use last year's details"; nothing they save is public;
- **Announce** makes it upcoming (a tab with its tentative dates, no homes), **Open** puts its homes on the map at
  once, and **Hide** on an upcoming event makes it hidden again (if it was the default, the newest open event, else
  the newest public one, becomes the default). An open or closed event is never hidden.

## Community branding and each event's branding

Since 0.16 the neighborhood's look and each event's look are separate ([ADR 0012](../adr/0012-community-and-event-branding.md)):

| Where | What it is | Shown on |
|---|---|---|
| **Organizer → Community & branding** | the community's name, subtitle, city (and "Show city under the name"), logo, contact, links and, if you like, a general **About the neighborhood** text | every event |
| **Organizer → Events → Edit → Branding of this event** | the event's emoji, headline and its accent part, intro text, main and accent colours, and **About this event** (what visitors read on the About page for it) | that event only |

- Every field of an event's branding is optional. An empty field uses the built-in look of that kind of event — the
  field shows it in grey — so a Christmas lights event is midnight blue and gold with "Holiday nights. / Bright lights."
  and a sale teal and coral with "Good finds. / Close to home." until you change them. The decorations of the top of the
  page (moon and ghost, tree, moving box) belong to the kind of event and cannot be changed.
- The About page of an event shows its own About text, else your general neighborhood text, else the built-in text of
  that kind of event. Rules (one per line) are set per event as before.
- Colours: type `#RRGGBB` or use the picker next to the field. Text and buttons on your colours stay readable in light
  and dark mode: the site adjusts the text colour automatically.
- See the result: **Preview this draft** for a draft, **Open this event's page** for a public one (save first).
- Next year's event (created when you close one) keeps the branding; change it there if you like.
- If you gave the map your own Halloween look before 0.16, the upgrade moved it to your Halloween events; your other
  events start from their own built-in look.
- Your own reminders (Community & branding) still show for every event; leave them empty for each kind of event's list.
- The organizer console itself is the neighborhood's: its header shows your logo (or the community's initial) and the
  console keeps its own neutral colours. An event's look shows only where that event is in focus — its row and form in
  Events, its preview, the Overview's list of events and the Addresses listing column, which names the event it shows
  ([D-20261031-52](../../DECISIONS.md)).

## When an event is over

**Events close by themselves after their last day**, at midnight in the community's time zone (within the hour: the check
runs hourly, and when the app starts, so an upgrade after the last day closes the event at once). A Christmas lights
season of November 1 – January 1 stays open across New Year and closes on January 2. Only open events close this way:
hidden, upcoming and draft events never do, even when their dates have passed. To keep an event going, change its last
day before it ends. The audit log shows the automatic close with the actor `cli`.

**Close** ends an open event earlier. It asks for confirmation and shows the thank-you text visitors will see, next year's
dates it will create, that next year's event is created hidden and residents can get ready for it, and where "/" goes.
Either way, in one step:

- the event's homes leave the map (also by direct link), it leaves the tabs and goes under **Past events** (a small link
  at the end of the tabs and in the footer) with the thank-you message, for example "Halloween
  2026 was a success — thank you! Come back next year (dates to be announced). Residents: sign in to get your house
  ready for next year." with a **Get my house ready** button to My house;
- next year's event of the same kind is created **hidden**: Halloween on October 31 of next year, Christmas lights on
  the same nights a year later, a sale with its dates to be announced — with the same name, hours, rules and lasting
  settings (never the best night, the sale days or the thank-you text);
- residents see next year's tab in My house ("Getting ready") and can list their homes for it; visitors do not see it
  until you **Announce** it. Then its tab appears, the default thank-you text names its dates ("Come back next year (Oct
  31, 2027, tentative)") and the thank-you card adds **See Halloween 2027** ([D-20261101-02](../../DECISIONS.md)).

Two organizers closing at the same moment, or an organizer and the automatic close, cannot both succeed (the organizer
gets "changed" or "not open" and reloads).

A thank-you page offers a way back to the current default event. Past events lists closed events, newest first; archive
one to take it off the public site.

## The default event

**Make default** on an upcoming or open event (never a draft, a hidden or a closed one): "/" shows it (Community &
branding names it), and it stays the default as long as it is on the site.

When the default event closes, is archived or is hidden, the default moves by itself to the current event happening
soonest: an open event whose dates include today, then the earliest starting open event, then the earliest announced
upcoming one. With none on the site, "/" keeps showing the closed event's thank-you message, and moves as soon as you
announce or open another event. Next year's hidden event is never the default. A typical year: Halloween open and default
in October, closing by itself on November 1 with the default moving to the Christmas lights (announced or open) and the
rummage sale announced as a second tab. The audit log shows each move as `event.default_moved`.

**Residents are not emailed automatically.** Inviting them to list for an event is manual for now: tell them through
your usual neighborhood channels (newsletter, group chat, flyer). Emailing residents from the app is planned work
([ROADMAP](../../ROADMAP.md)).

## No event at all

If you archive every public event, the public site says "No event on the map right now", listing is closed and no
homes are shown. Announce or open an event when you are ready.

## Who may come: each event's access

Under **Who may come** in an event's form, choose how visitors may reach the homes during this event, in the words of its
kind: open to the public; gated, with the gates open during the event ("Gated — gates open to shoppers during sale hours"
for a sale); gated for residents and guests; residents only; or not stated. **Same as the community** is the empty choice
and follows Organizer → Community & branding. For example, a rummage sale open to the public next to Christmas lights for
residents and guests.

The event's map (its Rules sheet), its About page and the public API show the event's own access. The community's access
notes (gates, entrances, parking) are written for the community's access, so they do not show on an event whose access is
different; put what visitors need in that event's rules instead. Next year's event keeps the access. `community.yaml`
can set it for the install's first event (`event.settings.access`). The site never grants gate access.

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
| `app:community:bootstrap <file>` (`--force`) | import `community.yaml` and its GeoJSON into an empty install (the first event comes from its `event` block and `event.settings`; the file's `branding` keys and `community.about` are that first event's branding) |
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
