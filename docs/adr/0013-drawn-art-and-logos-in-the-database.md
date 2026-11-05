# ADR 0013 — Drawn art instead of emoji, and logos in the database

**Status.** Accepted (owner request, 2026-11-05). Decisions D-20261105-01 to -07. Refines D-20261031-46, -49, -51 and -52.

**Context.** Emoji look different on every device and cannot be tinted, and the owner approved a drawn set: 100 icons, a brand
mark per event type, hero scenes and a C9 garland with rounder bulbs in classic colours. Logos sat only in the `branding` volume,
so restoring a database backup lost them, and one community logo could not suit a Halloween and a rummage sale. Production runs
0.9.0-beta.1 and an image-only rollback to it must keep working.

**Decision.**
- **Drawn art.** Templates and browser modules draw `<svg aria-hidden><use href=sprite#id>` through one helper each
  (`Twig/IconExtension`, `lib/icons.js`). Types name icons by id where they named emoji (`icon`, `event_icon`, `cta_icon`), so the
  API keeps those keys with new values; `branding.brand_emoji` leaves the public answers. Tests fail on any emoji or decorative
  glyph in templates, modules, styles, server strings or rendered pages.
- **Words people wrote stay.** Stored headlines, notes and reminders are shown as written, an emoji included.
- **Garland.** A decoration fragment every type provides (empty for Halloween and sales): its own row at the top of the page, so
  it can never cover a control, as a repeating background, so it can never cause sideways scrolling.
- **Logos.** A `logos` table with one row per owner: the community or an event. An event's page and emails show its logo, then the
  type's drawn mark; the console and pages with no event show the community's logo, then a neutral mark. Logos stay behind the one
  existing multipart endpoint, with the same checks.
- **Rollback.** The stored `branding.logo` and the volume file become a mirror for the 0.9 image: the mirrored Halloween's logo, else
  the community's. The migration copies an existing volume logo into the community and every Halloween and leaves the file.

**Consequences.** A database backup now carries the logos. An event's page no longer shows the community's logo, so an upgraded
Christmas lights or sale page changes from the logo to its drawn mark unless it is given its own; Halloween looks unchanged. A logo
uploaded on 0.9 after a rollback is replaced by the database's when this release runs again, as branding already is. Mail clients do
not draw SVG, so an SVG logo gives way to the mark in emails. The stored emoji stays only for the 0.9 mirror.

**Alternatives considered.**
- An inline SVG sprite in each page: heavier pages, no shared cache, and a second copy of the art in every response.
- A new upload endpoint per event: a change to nginx and the upload pool's rules (D-20261024-03) for what one field does.
- Copying the volume logo at each start instead of in a migration: it would bring back a logo an organizer removed.
- Stripping emoji from stored text at display: it changes people's words behind their backs.
- Rasterizing SVG logos for email on the server: an image library in the production image for a rare case.
