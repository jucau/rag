# ADR-0018 — nginx + PHP-FPM instead of `php artisan serve`

**Status:** Accepted — 2026-06-11. Amends ADR-0002 (the `app` service)
and replaces the `PHP_CLI_SERVER_WORKERS` workaround of ADR-0017.

## Context

`php artisan serve` (PHP's built-in dev server) is single-worker by
default. With SSE answers (ADR-0017) holding a connection open for the
whole generation (~10s), every other request was blocked. Raising
`PHP_CLI_SERVER_WORKERS` was a workaround, not an architecture.

## Decision

Split the `app` service into the standard production duo:

- **`app`** becomes a **PHP-FPM** pool (`php:8.5-fpm-alpine`):
  multi-worker by design, exposes FastCGI on port 9000 (internal only).
  Dev-only pragmatism: the pool runs as root (`php-fpm -R`) so workers
  write freely to the bind-mounted `storage/`; production would use
  `www-data` and proper ownership.
- **`nginx`** (`nginx:1.28-alpine`, pinned stable) listens on host port
  8000: serves static assets (`public/build`…) directly and forwards
  PHP to FPM. `fastcgi_read_timeout 600s` covers slow generations; SSE
  buffering is disabled per-response by the `X-Accel-Buffering: no`
  header the streaming endpoint sends. Healthcheck on Laravel's `/up`.

Workflow is unchanged: same URL, `make` targets and `docker compose
exec app …` untouched (FPM replaces serve in the same container).

## Consequences

- Verified: while an SSE answer streams, parallel requests respond in
  ~16ms — the single-process bottleneck is gone.
- The dev stack now mirrors a realistic production topology (reverse
  proxy → FPM pool), making the SSE/proxy interaction (buffering,
  timeouts) visible in development rather than discovered in prod.
- One more container and an nginx config file to maintain.
