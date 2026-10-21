# Upgrade and rollback

## Upgrade

1. Read `CHANGELOG.md` for the target version (migration notes, breaking changes).
2. `bin/backup-now.sh` and copy the dump off the host.
3. Update the compose files and pin the release: `git fetch --depth 1 origin tag vX.Y.Z && git checkout vX.Y.Z`, then set `APP_IMAGE_TAG=X.Y.Z` in `.env`.
4. `docker compose pull && docker compose up -d --force-recreate --wait` (migrations run on start; from source: `docker compose up -d --build --force-recreate --wait`). Recreating the containers is what clears PHP's OPcache: production never re-checks code files (`opcache.validate_timestamps=0`, fastest), so a new container is the only way new code runs. Never copy files into a running container.
   - Changed only `.env` or PHP settings? `docker compose up -d --force-recreate app maintenance` does the same without a rebuild.
   - Check the new version is live: the footer shows it, or `curl -s https://<host>/healthz`.
5. Check `/healthz`, the public map, organizer sign-in, one listing edit.

## Rollback

- Code-only change (no new migration): `git checkout <previous tag>`, set `APP_IMAGE_TAG` back to that version, then `docker compose pull && docker compose up -d --force-recreate --wait`. Older images stay in the registry, so a rollback never needs a build.
- With a schema migration: either run the down migration before switching code (`docker compose exec app php bin/console doctrine:migrations:migrate prev`) or restore the pre-upgrade backup with `bin/restore.sh`, then deploy the previous tag.

CI verifies every migration on a blank database up → down to zero → up again.
