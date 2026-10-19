# ADR 0001 — Self-hosted Symfony + PostgreSQL, one community per install

**Context.** A neighborhood needs a reliable map for a few hundred homes one night a year, run by a volunteer on a home server, reusable by other neighborhoods without code changes. Earlier drafts used Cloudflare Workers/D1 and React.

**Decision.** PHP 8.3 / Symfony 7.4 LTS with Doctrine DBAL (no ORM), PostgreSQL 17, Twig, Docker Compose (nginx + PHP-FPM + Postgres + backup), optional Cloudflare Tunnel. One community per install; everything community-specific is YAML/GeoJSON imported into the database and editable in the organizer console.

**Consequences.** Simple operations (one `docker compose up`), explicit SQL for the critical claim transaction, no multi-tenancy complexity. Scaling beyond one host is out of scope.

**Alternatives.** Serverless (D1/Workers): vendor lock-in and harder to self-host. ORM entities: more ceremony for little benefit at this size. Multi-tenant SaaS: explicitly excluded by the blueprint.
