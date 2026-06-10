# RAG — Pedagogical Laravel RAG project

Educational project to learn how Retrieval-Augmented Generation works:
Laravel 12 + PostgreSQL 17/pgvector (vector store) + Claude (generation,
`anthropic-ai/sdk`) + Ollama/embeddinggemma (local embeddings).

## Conventions

### Documentation (first-class citizen)

- Everything lives in [docs/](docs/README.md), written in **English**.
- **Concepts explained during working sessions must be captured in the
  docs** (`docs/02-rag-pipeline.md` or the glossary) — explanations must
  not stay only in chat.
- **Every structural decision gets an ADR** in `docs/adr/` at the time
  it is made.
- The [glossary](docs/glossary.md) is maintained continuously: every new
  key term gets an entry.

### Git

- Commit messages follow the **Gitmoji** convention (https://gitmoji.dev).
- Commit messages are written in **English**.

### Docker-only workflow

Nothing runs on the host — no local PHP, composer or psql required.
Everything goes through Docker:

```bash
make start                # boot the stack (app + db)
make shell                # shell inside the app container
docker compose exec app php artisan ...
docker compose exec app composer ...
docker compose exec db psql -U rag rag
```

### Code quality

Run `make quality` before committing. It chains:

| Tool | Command | Config |
|---|---|---|
| Rector (refactoring) | `make rector` (`make rector-dry` to preview) | `rector.php` |
| Pint (code style) | `make pint` | `pint.json` |
| PHPStan level max (static analysis) | `make stan` | `phpstan.neon` |

Tests run with `make testing`. Full CI suite: `make ci`.

## Services

| Service | Image | Port |
|---|---|---|
| app | PHP 8.4 (Alpine) + `php artisan serve` | 8000 |
| db | `pgvector/pgvector:pg17` | 5432 |
