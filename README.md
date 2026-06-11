# ⚽ RAG Football — a pedagogical RAG built with Laravel

A complete **Retrieval-Augmented Generation** system, built from scratch
to understand every moving part: ask questions about French football
(Ligue 1, clubs, players) and get answers **grounded in Wikipedia
articles**, with sources — something a bare LLM cannot do.

Everything runs locally in Docker: PostgreSQL + pgvector as the vector
store, Ollama for embeddings and generation (Claude available as an
alternative), Laravel 13 + Inertia/React for the chat UI.

> 📚 **This project is documentation-first.** The concepts (chunking,
> embeddings, retrieval, grounding…), every architecture decision (21
> ADRs) and the quality/benchmark reports live in [docs/](docs/README.md).

## Quickstart

The only prerequisite is **Docker** — no PHP, composer or node on the
host.

```bash
git clone <repo> rag && cd rag
cp .env.example .env
make setup          # stack + composer + Ollama models (~4GB) + migrations + front build
```

Then ingest some content and ask away:

```bash
# 1. Fetch articles from Wikipedia (raw content, incremental)
docker compose exec app php artisan rag:fetch-wikipedia \
  --title="Olympique lyonnais" --category="Catégorie:Club de Ligue 1"

# 2. Chunk + embed whatever is stale
make index

# 3. Ask — CLI…
docker compose exec app php artisan rag:ask "Qui est l'entraîneur de l'OM ?"

# …or chat UI with streaming + model selector
open http://localhost:8000
```

## Useful commands

| Command | What it does |
|---|---|
| `make start` / `make stop` | Boot / stop the stack |
| `make setup` | Full bootstrap from a fresh clone |
| `make fetch-all` | Refresh the corpus (unchanged pages are skipped) |
| `make index` | Chunk + embed stale documents only |
| `make quality` | Rector + Pint + PHPStan (level max) |
| `make testing` | Test suite |
| `make shell` | Shell inside the app container |
| `php artisan rag:ask "…" --provider=claude --model=…` | Query with a specific provider/model |
| `php artisan rag:benchmark` | Run the 50-question benchmark across local models |

Batch runs export structured JSON logs to `storage/rag-runs/`; every
question is logged with timings and sources in the `rag_queries` table.

## Services

| Service | Image | Port |
|---|---|---|
| nginx | `nginx:1.28-alpine` | 8000 |
| app | PHP-FPM 8.5 (Alpine) | 9000 (internal) |
| db | `pgvector/pgvector:0.8.2-pg17` | 5432 |
| ollama | `ollama/ollama:0.30.7` | 11434 |
| node | `node:22-alpine` (Vite dev server) | 5173 |

## Going further

- [Project overview](docs/01-project-overview.md) — why RAG, the architecture, the hybrid local/API pattern
- [The RAG pipeline](docs/02-rag-pipeline.md) — ingestion, chunking, retrieval, generation, performance
- [Answer quality](docs/03-answer-quality.md) & [model benchmark](docs/04-benchmark-local-models.md)
- [ADRs](docs/adr/README.md) — every decision with its context and trade-offs
- [Glossary](docs/glossary.md)

Conventions for contributors (and AI agents): [LLM.md](LLM.md).

## License & data

Code under MIT. The corpus is fetched from [Wikipedia](https://fr.wikipedia.org)
(CC BY-SA): every stored document keeps its source URL, and answers cite
their sources.
