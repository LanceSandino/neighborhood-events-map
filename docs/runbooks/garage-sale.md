# Running a garage or rummage sale (organizers)

Since 0.13 the map offers a third event type: the **Garage Sale** (`garage_sale`, ROADMAP Stage 4). Call it what your
neighborhood calls it — "Community Rummage Sale", "Spring Yard Sale" — the event's name is the public name. One install
still runs one event at a time ([D-20261021-20](../../DECISIONS.md)); residents keep their claimed homes and list them
for the sale from an empty form. This page is the organizer's checklist; the design is in
[event types](../architecture/event-types.md).

## What visitors get

- The sale days under the hero ("Sat–Sun, April 10–11, 2027"), "Most homes: 8 AM–2 PM · Individual hours vary", your
  early-bird time, and a pill that says **Coming this spring** (the season of the first sale day) before the sale,
  **Today** on a sale day, **More sale days ahead** between two sale days that are not next to each other, and **Sale
  over** afterwards.
- Homes with their item categories as icons with words (kids & baby, toys, furniture, clothing, tools, books & media,
  electronics, sports, household, free items), **Multi-family**, **Early birds welcome** and the payment kinds they take
  (cash, card, payment apps). No photos, prices, contact details or payments: the map sells nothing.
- Quick filters Kids & toys, Furniture, Tools and Saved; under Filters: Household, Electronics, Free items, Open now,
  **Open <weekday>** for each sale day, Multi-family and Early birds welcome. The search box finds streets, categories
  and words in the notes.
- Each home's status on the visitor's own clock: **Open now** only inside that home's hours today, **Opens 9 AM**,
  **Opens Sun 9 AM**, **Paused today**, **Sold out today** (never shown as open) and **Closed**. A paused, sold-out or
  closed home's pin is greyed on a sale day.
- "Your sale stops": saved stops in a suggested order. A **driving tour** with directions appears only when you allow
  driving for the event and a routing provider is configured; otherwise it is an ordered list of stops with no lines
  and no times.
- The event rules, the early-bird time and the sale etiquette (early birds, parking and traffic, driveways and private
  yards, no recalled or prohibited items, the map sells nothing) on the Rules sheet, the About page and next to the
  stops.

## Start a sale

1. **Organizer → Events → + Prepare an event.** Choose *Garage Sale*, the public name, the first and last day, the hours
   most homes keep and up to eight short rules (parking, one side of the street…). Then the sale's own settings:
   - **Sale days** — up to three. Leave them empty for a sale of up to three days: every day is a sale day. For a longer
     span (for example two Saturdays a week apart) name each day.
   - **Categories residents may list** — all ten by default; untick what your sale does not take.
   - **Early birds not before** (optional) — shown with the sale and next to the stops.
   - **Offer a driving tour** — directions need a routing provider (below).
2. **Preview** the draft: it shows the sale theme, words, days and filters (and no homes).
3. **Put on the map** when you are ready. The current event ends at that moment: its homes leave the map and stay with
   their season; nothing is copied. Residents see "List for Community Rummage Sale" in My house.
4. Optional: under **Community & branding**, adjust the words or colours. Branding you never changed already follows the
   sale (teal and coral, "Good finds. Close to home."); anything you changed stays as you set it
   ([D-20261031-16](../../DECISIONS.md)). Teal buttons carry dark text so they stay readable
   ([D-20261031-32](../../DECISIONS.md)).
5. For a driving tour, enable the routing provider under Community & branding → Map & routes (an openrouteservice key,
   `ROUTING_PROVIDER=openrouteservice` and `ROUTING_API_KEY`).

A sale day that homes already chose cannot be removed (the console says how many listing days fall on it); a day nobody
chose can go. Moving the sale to other dates works the same way as for any event (Organizer → Events → Edit).

## What residents enter

The sale days they take part in and their **own hours on each day** (Saturday and Sunday may differ), at least one
category, multi-family, early birds welcome, the payment kinds (the kind only — never a username or phone number) and a
short public note. A note with a **phone number, email address, payment username or handle, or web address** is
refused with a message asking them to remove it; nothing of that listing is saved, not even hidden, and no email is
sent ([D-20261031-25](../../DECISIONS.md)). In My house they mark **Paused today** or **Sold out today** (both hold only
for that day) or **Closed**.

Business sellers or prohibited items: open the listing on the map, use "Report this listing", and hide it under
Organizer → Addresses & claims as for any listing.

## A new install that starts with a sale

`event.type: garage_sale` with a date range in `community.yaml` creates the first event (the settings bootstrap,
[setup](setup.md)). Since 0.13 `event.settings` sets that first event's rules and the sale's own settings; they are
checked exactly like the console checks them, and nothing is imported when one is refused
([D-20261031-29](../../DECISIONS.md)). Later changes go through Organizer → Events. The fictional demo is a complete
example: [examples/demo-sale/community.yaml](../../examples/demo-sale/community.yaml).

```yaml
event:
  type: garage_sale
  name: "Community Rummage Sale"   # the public name; leave it out for "Garage Sale"
  date: "2027-04-10"               # the first day (the same as starts_on)
  starts_on: "2027-04-10"
  ends_on: "2027-04-11"
  starts_at: "08:00"               # most homes' hours
  ends_at: "14:00"
  settings:
    days: ["2027-04-10", "2027-04-11"]               # 1–3 sale days inside the dates (optional for up to three days)
    categories: [kids_baby, toys, furniture, clothing, tools, books_media, electronics, sports, household, free]
    early_bird_time: "07:30"                         # optional
    rules:
      - "Park on one side of the street so traffic can pass."
    route_modes: [walking, driving]                  # driving needs a routing provider
# Leave branding out to use the sale defaults, or set your own colours and words.
```

## Try it locally

The fictional sale demo runs as a fourth dev stack on port 8090 (Mailpit 8028): copy `.env.demo` to `.env.sale`, set
`COMPOSE_PROJECT_NAME=halloween-sale`, `HTTP_PORT=8090`, `APP_BASE_URL=http://localhost:8090`, `MAILPIT_UI_PORT=8028`
and `COMMUNITY_BOOTSTRAP_FILE=/app/examples/demo-sale/community.yaml`, then
`docker compose --env-file .env.sale up -d --build --wait`. Its ten invented homes cover Sat–Sun, April 10–11, 2027 with
different days, hours and categories, one sold out on Saturday, one paused on Sunday and one closed.
