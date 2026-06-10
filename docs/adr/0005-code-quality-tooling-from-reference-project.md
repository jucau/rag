# ADR-0005 — Code quality tooling mirrored from the reference project

**Status:** Accepted — 2026-06-10

## Context

The author maintains a production Laravel codebase with an established
quality toolchain. Reusing the same tools and configurations here means
zero relearning cost and battle-tested rules.

## Decision

Mirror the reference project's toolchain, adapted to a fresh codebase:

- **Pint** (`pint.json`): psr12 preset + strict rules
  (`declare_strict_types`, ordered imports, PHPUnit attributes,
  snake_case test methods).
- **PHPStan via Larastan** (`phpstan.neon`): level **max** from day one.
  No baseline and no `example/local` config split — those exist in the
  reference project to manage legacy code and per-developer overrides,
  which a fresh codebase doesn't need.
- **Rector** (`rector.php`): same prepared sets and stylistic skip list
  as the reference, plus Laravel 12 sets and PHP 8.4.
- A **Makefile** with the same layout (`make quality` = rector → pint →
  stan).

One commit per tool, so each configuration is reviewable in isolation.

## Consequences

- Level max on a fresh project means errors are fixed as they appear —
  no baseline debt ever accumulates.
- Consistency with the author's daily environment.
- Rector and Pint occasionally fight over style; running them in the
  `quality` order (rector first, pint after) keeps the result stable.
