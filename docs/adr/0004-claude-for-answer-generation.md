# ADR-0004 — Claude (Anthropic API) for answer generation

**Status:** Accepted — 2026-06-10

## Context

The generation step takes the user's question plus the retrieved chunks
and writes the final answer. This requires reasoning, synthesis and good
French — capabilities where small local models (3–8B parameters, the only
size practical on a laptop CPU) fall clearly short of frontier models.
Generation is also called only **once per question**, unlike embeddings.

## Decision

Use **Claude** through the Anthropic API for generation, via the official
PHP SDK (`anthropic-ai/sdk`). Target model: `claude-opus-4-8`.

Embeddings are *not* taken from Anthropic — they don't offer an embeddings
endpoint; that concern is addressed separately (ADR-0006).

## Consequences

- High answer quality; the RAG's weak points will be retrieval quality
  (chunking, top-k), not generation — which is the right place to focus
  learning.
- Requires an `ANTHROPIC_API_KEY` and has a per-call cost (one call per
  question; cheap at pedagogical volume).
- Hybrid architecture (local embeddings + API generation) — a common
  production pattern, documented in the
  [project overview](../01-project-overview.md).
