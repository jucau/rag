# ADR-0025 — Measure retrieval quality separately (recall@k, MRR)

**Status:** Accepted — 2026-06-14. Complements the generation benchmark
([ADR-0011](0011-log-rag-queries-with-timings.md),
[docs/06](../06-benchmark-v3-enriched-corpus.md)).

## Context

Three benchmarks (v1–v3) graded the **final answer text**. That number
mixes two very different failures:

1. **Retrieval failure** — the chunk holding the answer never reached
   the prompt. No model can recover from this.
2. **Generation failure** — the right chunk *was* in the prompt, but the
   model refused, picked the wrong passage, or fabricated.

v3 concluded "the real lever is retrieval" — but that was an *inference*
from answer text, never measured directly. Before investing in a
cross-encoder reranker, query rewriting or hybrid search, we need to
know **how good retrieval already is**, so we optimise the actual
bottleneck instead of an assumed one. "A diagnosis is only proven when
the fix makes the symptom disappear" (the 116-titles lesson) applies to
*where* the symptom lives, too.

## Decision

Add a dedicated, generation-free metric:

- **Ground truth (qrels)** — `benchmark/retrieval-gold.json` maps every
  *answerable* question to the document title(s) that hold the answer.
  Judged at the **document level**: titles are stable across re-chunking
  (chunk ids are not), and "is the right article retrieved?" is the
  question retrieval can actually be held responsible for. A question is
  a *hit@k* if at least one relevant document appears in the top-k.
- **Scope** — only the A/B/C/D/H/I categories (factual, historical,
  synthesis, disambiguation, multi-doc, players) are judged. The E
  (fabrication traps), F (off-corpus) and G (biased) categories have no
  relevant document *by design* — they test the guard and the generator,
  not retrieval — so they are intentionally excluded. A unit test
  ([RetrievalGoldTest](../../tests/Unit/Rag/RetrievalGoldTest.php))
  enforces this scope and the integrity of every judgment.
- **Command** — `rag:retrieval-eval` embeds each judged question,
  retrieves a deep pool (default 20, no distance guard), and reports
  **recall@{1,3,5,10,20}**, **MRR**, the count of *true misses* (no
  relevant chunk anywhere in the pool) and of *threshold drops* (a
  relevant chunk inside the top-k but cut by the 0.65 guard). Results are
  written to a structured execution log ([ADR-0021](0021-structured-execution-logs.md)).

The command loads **no generation model**: it isolates the context the
model is handed from what the model does with it.

## Consequences

- We can now attribute each backlog item to the right phase and measure
  any retrieval change (reranking, hybrid, query rewriting) against a
  number instead of a hunch. recall@5 vs recall@20 quantifies exactly
  how much headroom a reranker has.
- The first run **refined the v3 narrative** (see
  [docs/07](../07-retrieval-metrics.md)): document-level recall@5 is
  already ≈ 95 % and MRR ≈ 0.89, so gross retrieval is *not* the main
  bottleneck — the larger gap to ≈ 81 % answer accuracy is generation
  robustness plus a handful of genuinely hard retrieval cases. This
  tempers the "build a reranker now" reflex.
- The gold file is a maintained artefact: adding corpus or questions
  means adding judgments. The test catches a typo'd id or an empty list,
  but the *correctness* of a judgment (does this article really hold the
  answer?) stays a human responsibility — a few were verified against
  chunk content (Geoffroy-Guichard capacity lives in the ASSE article,
  not a dedicated one).
- Document-level judging is **lenient**: "right article in top-5" does
  not guarantee the specific answer *passage* is there. So recall@5 is
  an upper bound on retrieval health; chunk-level precision is a finer,
  later refinement if the answer gap warrants it.
