# Upgrade and rollback

## Upgrade

1. Read `CHANGELOG.md` for the target version (migration notes, breaking changes).
2. `bin/backup-now.sh` and copy the dump off the host.
3. `git fetch --tags && git checkout vX.Y.Z`
4. `docker compose up -d --build --force-recreate --wait` (migrations run on start). Recreating the containers is what clears PHP's OPcache: production never re-checks code files (`opcache.validate_timestamps=0`, fastest), so a new container is the only way new code runs. Never copy files into a running container.
   - Changed only `.env` or PHP settings? `docker compose up -d --force-recreate app maintenance` does the same without a rebuild.
   - Check the new version is live: the footer shows it, or `curl -s https://<host>/healthz`.
5. Check `/healthz`, the public map, organizer sign-in, one listing edit.

## Rollback

- Code-only change (no new migration): `git checkout <previous tag> && docker compose up -d --build --wait`.
- With a schema migration: either run the down migration before switching code (`docker compose exec app php bin/console doctrine:migrations:migrate prev`) or restore the pre-upgrade backup with `bin/restore.sh`, then deploy the previous tag.

CI verifies every migration on a blank database up → down to zero → up again.
