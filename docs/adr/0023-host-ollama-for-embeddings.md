# ADR-0023 — Host-native Ollama for embeddings too (Metal GPU)

**Status:** Accepted — 2026-06-13. Extends
[ADR-0022](0022-host-ollama-for-generation.md) (which moved generation
to the host) to embeddings.

## Context

After generation moved to the host GPU (ADR-0022), embeddings remained
on the dockerised, CPU-only Ollama — the slow part of ingestion
(~1.7s/chunk, a full re-index ≈ 2h13 on the corpus). The owner wanted
embeddings on the host GPU too, for faster (re-)ingestion.

The risk flagged in ADR-0006: stored chunk vectors and query vectors
**must come from the same embedding model/instance**, or retrieval
drifts. The corpus was embedded by the dockerised Ollama; embedding
queries on the host would mix two spaces.

**Measurement before deciding.** Embedding the same text on both:

```
cosine(docker-CPU vector, host-GPU vector) = 0.99999142
```

The two are float-noise apart (~1e-5) — far below the retrieval
distance gaps (relevant 0.41–0.49, threshold 0.65). So switching is
low-risk even *without* re-embedding. We re-embed anyway, for clean
single-space consistency and because that is precisely where the GPU
speed-up applies.

## Decision

- Embeddings target the host Ollama via a new `OLLAMA_EMBED_URL`
  (config `rag.embeddings.ollama.url`), symmetric with `OLLAMA_CHAT_URL`.
  Both fall back to `OLLAMA_URL` (the dockerised Ollama), so the default
  fully-dockerised stack is unchanged; the host URLs are opt-in
  (commented in `.env.example`).
- The host must have `embeddinggemma` pulled.
- **The corpus is re-embedded on the host** (`rag:index --force`) so
  stored chunks and future queries share one space (ADR-0006).

## Consequences

- (Re-)ingestion is dramatically faster on the GPU. The full-corpus
  re-embed that applied this ADR: **2m27s for 4 780 chunks (~31ms/chunk)
  vs ~2h13 (~1 746ms/chunk) on CPU — ~56× faster**. Per-query embedding
  also drops from ~250–870ms to ~130ms.
- With both `OLLAMA_EMBED_URL` and `OLLAMA_CHAT_URL` set to the host,
  the **dockerised Ollama is idle** for this developer — kept only as
  the default/fallback path for a fresh, host-Ollama-less clone.
- Embeddings are now produced by an unpinned host Ollama, dropping the
  reproducibility anchor ADR-0022 had deliberately kept on the docker
  side. Accepted for the speed; the 0.99999 agreement shows the model
  output is stable across the two instances anyway.
- Switching the embedding endpoint does **not** bump
  `DocumentIndexer::VERSION` (chunk *text* is unchanged, only the
  vectors): `rag:index --force` is the right tool, not staleness
  detection.
