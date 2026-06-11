# ADR-0016 — Keep gemma3:4b for local generation; pin models in memory

**Status:** Superseded by [ADR-0019](0019-llama32-default-after-50q-benchmark.md) — the 50-question benchmark reversed the conclusion of the 5-question one. Originally accepted 2026-06-11.

## Context

Local generation felt slow (~10-30s per answer). Four candidates were
benchmarked on the same 5 questions over the enriched corpus, warm
models, all runs logged in `rag_queries` — full results in
[docs/03-answer-quality.md](../03-answer-quality.md):

- gemma3:4b — median 12.4s, quality 4/5 (only model to answer the
  current-coach question from the infobox);
- llama3.2:3b — 9.9s, 4/5 (only model to refuse fabricating a
  nickname, but failed the infobox question);
- qwen3:1.7b — 8.9s, 2.5/5 (confident fabrications);
- gemma3:1b — 5.1s, 2/5 (unusable).

Separately, the timing log showed 30–100s outliers caused by **model
reloads**: each query uses two models (embeddings + chat), and Ollama
evicted one to load the other under default keep-alive (5 min).

## Decision

1. **Keep `gemma3:4b` as the default chat model.** Saving 2.5s with
   llama3.2:3b is not worth losing infobox-grounded current facts;
   below 3B, speed is bought with confident fabrication — the worst
   failure mode for a RAG.
2. **Pin models in memory**: `OLLAMA_KEEP_ALIVE=-1` (never unload) and
   `OLLAMA_MAX_LOADED_MODELS=2` on the ollama service, eliminating
   reload-induced outliers. Requires ~4.5GB RAM in the Docker VM for
   the two pinned models.
3. The remaining levers are documented, not applied: `RAG_TOP_K` to
   shrink prompt processing, response streaming (perceived latency),
   native Ollama with Metal GPU (breaks ADR-0002), or a Claude API key.

## Consequences

- Worst-case latency drops from ~100s to the model's true speed
  (~8-15s); the default stays the best-grounded local model.
- The benchmark protocol (same questions, logged runs, quality scored
  against sources) is reusable for any future model candidate.
- gemma3:4b's residual fabrication (the "nickname") remains the open
  issue — the designated comparison is `--provider=claude`.
