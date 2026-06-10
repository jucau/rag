# ADR-0002 — Docker-only development environment

**Status:** Accepted — 2026-06-10

## Context

The development machine (macOS) should not need PHP, Composer, PostgreSQL
or any model runtime installed on the host. The whole stack must be
reproducible from a fresh clone with Docker as the only prerequisite.

## Decision

Everything runs in Docker Compose:

- `app`: PHP 8.4 (Alpine) with `pdo_pgsql`, running `php artisan serve`.
  The Composer binary is baked into the image, so even dependency
  management goes through the container.
- `db`: PostgreSQL 17 with pgvector preinstalled.
- `ollama`: local inference for embeddings.

Even the initial Laravel scaffolding was generated through the official
`composer` Docker image. A `Makefile` wraps the common commands
(`make start`, `make quality`, `make shell`…).

## Consequences

- One-command bootstrap: `make setup` brings up the stack, installs
  dependencies, pulls the embedding model and migrates.
- No version drift between host and project; nothing to install on the host.
- Docker on macOS runs in a VM: no GPU (Metal) access. Fine for
  embeddings on CPU; would be a limitation for local *generation*
  (see ADR-0004 and ADR-0006).
- Slight overhead: every command is prefixed with
  `docker compose exec app` (mitigated by the Makefile).
