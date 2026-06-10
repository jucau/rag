# ADR-0014 — Retrieval relevance threshold and hardened grounding prompt

**Status:** Accepted — 2026-06-11

## Context

Two more findings from the quality audit
([docs/03-answer-quality.md](../03-answer-quality.md)):

- Off-topic input (*"tié trop le sang purée"*) still went through the
  full pipeline: nearest-neighbour search **always returns k results**,
  however far away they are — the LLM then "answered" from noise (in
  English, about RC Lens), burning 100s of CPU.
- The small generation model juxtaposed contradictory chunks without
  arbitrating (OL founded "in 1896 … created in 1950") and turned a
  description into a fact (Juninho "nicknamed" *free-kick specialist*).

Measured cosine distances separate cleanly: relevant questions land at
0.41–0.49 from their best chunk, off-topic ones at 0.77+.

## Decision

1. **Relevance threshold** (`rag.retrieval.max_distance`, default
   **0.65**): retrieved chunks farther than this are dropped; if none
   survive, a canned "out of corpus" answer is returned **without
   calling the generation model** (logged with provider
   `guard (out-of-corpus)`).
2. **Hardened system prompt**: facts must be explicit in the context
   (no guessing, no description-to-fact promotion); facts about an
   entity must come from a block whose `[Article : …]` header matches
   that entity, conflicts must be arbitrated in favour of the dedicated
   article and mentioned; answers are in French unless the question
   clearly isn't.
3. **Per-source distance in the logs**: each `sources[]` entry in
   `rag_queries` now carries its cosine `distance`, so relevance can be
   analysed historically and the threshold re-calibrated from data.

## Consequences

- Junk input gets an instant, honest answer (and ~0ms of generation).
- The threshold is a trade-off: too low rejects legitimate niche
  questions; 0.65 leaves a wide margin on both sides of the measured
  distributions. The logged distances make future tuning empirical.
- Prompt arbitration rules only work because chunks now carry their
  origin (ADR-0013) — the two decisions are a pair.
