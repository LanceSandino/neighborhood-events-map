# Halloween night runbook

**Before (a week ahead):** approve boundary, open listings, announce the URL, run a restore drill, test email delivery, check that the event date/time zone are right, export claims privately (`/api/admin/export/claims.csv`).

**On the night:**
- Watch `/admin` (open review requests, hidden listings). Hide abusive listings immediately (Addresses & claims → Hide); investigate later.
- Residents update their own status ("Out of candy", "Done"). The public map refreshes every minute.
- If email is down: listings already on the map keep working; residents cannot sign in until SMTP recovers.
- If the map tiles fail: the list of homes and My route keep working (Share sends the stop list as a link).
- Load: a few hundred homes and thousands of views are fine for one PHP-FPM host; nginx serves static assets.

**After:** close listings (Overview), set next year's event date (hides this year's listings until owners re-save) or run `bin/console app:season:archive`; keep the backups; delete data on request: residents can release their own home; the operator removes an account and everything tied to it with `bin/console app:user:delete <email> --confirm` (claims, listings, pending links, review requests; only an anonymous audit line remains).

**Incidents:** wrong owner → review request → verify offline → transfer (audited). Abuse → hide listing, raise rate limits only if needed, check audit log.
