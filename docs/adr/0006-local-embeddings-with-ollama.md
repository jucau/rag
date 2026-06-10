# ADR-0006 — Local embeddings with Ollama (embeddinggemma)

**Status:** Accepted — 2026-06-10. Replaces the initial intention to use
Voyage AI (Anthropic's recommended embeddings partner) before any code was
written.

## Context

Anthropic does not provide an embeddings endpoint, so the embedding model
has to come from elsewhere. Two families of options:

1. **Hosted API** (Voyage AI, OpenAI, Cohere…): best-in-class quality,
   but per-call cost, network latency on every call, an extra API key,
   and document content leaves the machine.
2. **Local model**: embedding models are small (100M–600M parameters) and
   run well on CPU; open-weight models are nowadays excellent at this
   task. Also a learning opportunity: see how local inference is wired
   into an application.

Embeddings are the *high-volume* side of a RAG (one call per chunk at
ingestion, one per question at query time), which amplifies the cost and
privacy arguments for local. Constraints: must run inside Docker (no GPU
on macOS — CPU only) and handle French documents well.

## Decision

Run embeddings locally with **Ollama** as a Compose service, serving
**`embeddinggemma`** (Google, ~300M parameters, ~620MB, multilingual,
**768 dimensions**).

- Ollama exposes a trivial REST API (`POST /api/embed`), needs no API key,
  and stores models in a named Docker volume.
- `embeddinggemma` was chosen over `bge-m3` (the other strong multilingual
  candidate, 1024 dims, ~1.2GB) for its smaller size and CPU speed —
  amply sufficient for a pedagogical corpus.

Generation stays on Claude (ADR-0004): the resulting hybrid
(local embeddings + API generation) is a common production pattern.

## Consequences

- Free, private, offline-capable embeddings; no API key to manage.
- The vector dimension (768) is now **frozen into the database schema**
  (`vector(768)`). Switching to a model with a different dimension means a
  migration plus full re-ingestion — a structural coupling documented in
  [the pipeline doc](../02-rag-pipeline.md).
- The same model must be used at ingestion and at query time; the
  configuration (`config/rag.php`) is the single source of truth.
- CPU inference is slower than a hosted API per call, but with no network
  round-trip — negligible at our scale.
- Quality ceiling is slightly below the best hosted models (acceptable
  here; revisit with a new ADR if retrieval quality disappoints).
