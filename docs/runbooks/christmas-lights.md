# Running a Christmas lights season (organizers)

Since 0.12 the map offers a second event type: **Christmas Lights** (`holiday_lights`, ROADMAP Stage 3). One install
still runs one event at a time ([D-20261021-20](../../DECISIONS.md)); residents keep their claimed homes and list them
for the lights season from an empty form. This page is the organizer's checklist; the design is in
[event types](../architecture/event-types.md).

## What visitors get

- A season of nights (up to 62, for example Dec 1 – Jan 3) under the hero: the dates, "Most homes: 6–10 PM · Times vary
  by house", and a pill that says **Season preview** before the first night, **Tonight** during the season and
  **Season over** after it.
- Homes with their display styles (classic, animated, light show to music, inflatables, walk-through), **Drive-by** or
  **Walk-up**, music with its FM station, treats or cocoa, and either **No flashing reported** or **Flashing lights**.
  Flashing warnings are self-reported; the site never calls a display safe.
- Quick filters Drive-by, No flashing reported, Music and Saved; under Filters: Walk-up welcome, Open tonight, one night
  of the season and a display style.
- Each home's status on the visitor's own clock: **Lights on** only inside that home's hours tonight, **Later tonight**,
  **Not tonight**, **Off tonight** (for that one night) and **Done for the season**.
- "Your lights tour": saved stops in a suggested order. A **drive-by tour** with driving directions appears only when
  you allow driving for the event and a routing provider is configured; otherwise it is an ordered list of stops with no
  lines and no times.
- The event rules, the quiet hours and the viewing etiquette on the About page and next to the tour.

## Start a lights season

1. **Organizer → Events → + Prepare an event.** Choose *Christmas Lights*, the first and last night, the hours most homes
   keep, up to eight short rules (parking, one-way streets…), the **best night** for a neighborhood walk (optional),
   **quiet hours** (optional) and whether to **offer a drive-by tour**.
2. **Preview** the draft: it shows the lights theme, words and filters (and no homes).
3. **Put on the map** when you are ready. The current event (for example Halloween) ends at that moment: its homes leave
   the map and stay with their season; nothing is copied. Residents see "List for Christmas Lights" in My house.
4. Optional: under **Community & branding**, adjust the words or colours. Branding you never changed already follows the
   lights type (midnight blue and gold, "Holiday nights. Bright lights."); anything you changed stays as you set it
   ([D-20261031-16](../../DECISIONS.md)).
5. For a drive-by tour, enable the routing provider under Community & branding → Map & routes (it needs an
   openrouteservice key, `ROUTING_PROVIDER=openrouteservice` and `ROUTING_API_KEY`). Driving routes are cached and rate
   limited like walking paths.

Residents choose **every night** or **only some nights**, their hours, styles, drive-by only (please do not approach)
or walk-up welcome, music and its FM station, the flashing warning, treats or cocoa and a short note. In My house they
mark a single night **Off tonight** or the whole season **Done for the season**.

## A new install that starts with lights

`event.type: holiday_lights` with a date range in `community.yaml` creates the first event (the settings bootstrap,
[setup](setup.md)). The fictional demo is a complete example: [examples/demo-lights/community.yaml](../../examples/demo-lights/community.yaml).

```yaml
event:
  type: holiday_lights
  name: "Christmas Lights"
  date: "2026-12-01"        # the first night (the same as starts_on)
  starts_on: "2026-12-01"
  ends_on: "2027-01-03"     # at most 62 nights
  starts_at: "18:00"        # most homes' hours
  ends_at: "22:00"
# Leave branding out to use the lights defaults, or set your own colours and words.
```

Rules, the best night, quiet hours and the drive-by tour are event settings: set them under Organizer → Events.

## Try it locally

The fictional lights demo runs as a third dev stack on port 8089 (Mailpit 8027): copy `.env.demo` to `.env.lights`, set
`COMPOSE_PROJECT_NAME=halloween-lights`, `HTTP_PORT=8089`, `APP_BASE_URL=http://localhost:8089`, `MAILPIT_UI_PORT=8027`
and `COMMUNITY_BOOTSTRAP_FILE=/app/examples/demo-lights/community.yaml`, then
`docker compose --env-file .env.lights up -d --build --wait`. Its ten invented homes cover Dec 1, 2026 – Jan 3, 2027
with different nights, hours and styles, one "off tonight" on Dec 12 and one done for the season.
