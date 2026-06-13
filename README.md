# ⚽ RAG Football — a pedagogical RAG built with Laravel

A complete **Retrieval-Augmented Generation** system, built from scratch
to understand every moving part: ask questions about French football
(Ligue 1, clubs, players) and get answers **grounded in Wikipedia
articles**, with sources — something a bare LLM cannot do.

Everything runs locally in Docker: PostgreSQL + pgvector as the vector
store, Ollama for embeddings and generation (Claude available as an
alternative), Laravel 13 + Inertia/React for the chat UI. Generation can
optionally run on a host-native Ollama for the GPU (see below).

> 📚 **This project is documentation-first.** The concepts (chunking,
> embeddings, retrieval, grounding…), every architecture decision (22
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

## Local AI models (Ollama)

The RAG uses two kinds of local model, both served by Ollama:

| Role | Model | Size | Required? |
|---|---|---|---|
| **Embeddings** | `embeddinggemma` | ~620 MB | ✅ yes — turns text into the 768-dim vectors stored in pgvector |
| **Generation (default)** | `llama3.2:3b` | ~2 GB | ✅ yes — writes the answers (best model of the [benchmark](docs/05-benchmark-103-questions.md)) |
| Generation (alternative) | `gemma3:4b` | ~3.3 GB | optional — offered in the chat UI selector |
| Benchmark only | `gemma3:1b`, `qwen3:1.7b` | ~0.8 / 1.4 GB | optional — only for `rag:benchmark` |

`make setup` (and `make ollama-pull`) pulls the first three into the
**dockerised** Ollama automatically — nothing to install on the host.
The two benchmark-only models are pulled on demand:

```bash
docker compose exec ollama ollama pull gemma3:1b
docker compose exec ollama ollama pull qwen3:1.7b
```

### Optional: run on the host GPU (much faster)

Docker on macOS has no access to the Metal GPU, so the dockerised
Ollama runs on CPU (generation ~8.6s/answer, embedding ~1.7s/chunk).
To use your Mac's GPU instead, run Ollama **natively on the host** and
point the RAG at it. Two opt-in URLs, independent:

- `OLLAMA_CHAT_URL` → host generation, ~3× faster
  ([ADR-0022](docs/adr/0022-host-ollama-for-generation.md))
- `OLLAMA_EMBED_URL` → host embeddings, much faster (re-)ingestion
  ([ADR-0023](docs/adr/0023-host-ollama-for-embeddings.md))

```bash
# On the host (not in Docker): install Ollama from https://ollama.com
ollama pull llama3.2:3b      # generation model
ollama pull embeddinggemma   # embedding model

# Uncomment in your .env (both already wired in compose):
# OLLAMA_CHAT_URL=http://host.docker.internal:11434
# OLLAMA_EMBED_URL=http://host.docker.internal:11434
docker compose exec app php artisan config:clear

# Embeddings: re-embed the corpus on the host so stored chunks and
# query vectors share one space (the two embeddinggemma instances agree
# to cosine 0.99999, but this keeps it strictly consistent — ADR-0006)
docker compose exec app php artisan rag:index --force
```

Both are opt-in: leave the URLs commented and the stack stays fully
dockerised (CPU). You can enable just generation, just embeddings, or
both.

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
| `php artisan rag:benchmark` | Run the 103-question benchmark across local models |

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
- [Answer quality](docs/03-answer-quality.md) & model benchmark ([v1, 50 q](docs/04-benchmark-local-models.md) · [v2, 103 q](docs/05-benchmark-103-questions.md))
- [ADRs](docs/adr/README.md) — every decision with its context and trade-offs
- [Glossary](docs/glossary.md)

Conventions for contributors (and AI agents): [LLM.md](LLM.md).

## License & data

Code under MIT. The corpus is fetched from [Wikipedia](https://fr.wikipedia.org)
(CC BY-SA): every stored document keeps its source URL, and answers cite
their sources.
