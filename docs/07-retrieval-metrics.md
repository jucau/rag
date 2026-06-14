# Retrieval metrics — measuring the context, not the answer (2026-06-14)

The three previous benchmarks ([v1](04-benchmark-local-models.md),
[v2](05-benchmark-103-questions.md), [v3](06-benchmark-v3-enriched-corpus.md))
all graded the **final answer**. A wrong answer can mean two opposite
things:

1. the chunk with the answer **never reached the prompt** (retrieval's
   fault — no model can recover), or
2. the right chunk **was in the prompt** but the model mishandled it
   (generation's fault).

v3 *concluded* "the real lever is retrieval" — but it never measured
retrieval directly. This document does, with `rag:retrieval-eval`
([ADR-0025](adr/0025-measure-retrieval-separately.md)). It loads **no
generation model**: it scores the *context* the pipeline hands the
model, in isolation.

## Method — ground truth (qrels) and two metrics

For every **answerable** question we wrote down the document(s) that
hold the answer: `benchmark/retrieval-gold.json`. The judgment is at the
**document level** — titles are stable across re-chunking, and "is the
right *article* retrieved?" is what retrieval can be held responsible
for. A question is a **hit@k** if at least one of its relevant documents
appears in the top-k retrieved chunks.

Annotating by hand surfaced facts a naive script would miss: there is
**no** "Stade Geoffroy-Guichard" or "Stade Vélodrome" article — those
capacities live inside the *ASSE* and *OM* articles, so that is where
the gold points. (Verified against chunk content.)

Two metrics:

- **recall@k** — share of questions whose answer document is in the
  top-k. `recall@5` = what the generator sees today; `recall@20` = the
  ceiling a reranker pulling from a deeper pool could reach.
- **MRR** — mean of `1 / rank` of the first relevant chunk. Rewards
  ranking the right chunk *high*, not merely having it somewhere.

**Scope.** Only A/B/C/D/H/I categories (74 questions) are judged. The E
(fabrication traps), F (off-corpus) and G (biased) categories have *no*
relevant document by design — they test the guard and the generator, not
retrieval — and are excluded. A unit test enforces this.

## Results (corpus: 217 docs / 6 893 chunks, strategy 2026-06-13.1)

| Metric | Value |
|---|---|
| recall@1 | 85.1 % |
| recall@3 | 93.2 % |
| **recall@5** (production window) | **94.6 %** |
| recall@10 | 94.6 % |
| recall@20 | 97.3 % |
| **MRR** | **0.894** |
| true misses (no relevant chunk in top-20) | 2 / 74 |
| threshold drops (relevant in top-5 but cut by the 0.65 guard) | 0 / 74 |

recall@5 by category: synthèse, désambiguïsation, joueurs **100 %**;
factuel-simple 95.8 %, factuel-historique 95.0 %; **complexe-multidoc
75 %** (the weak spot).

## The counter-intuitive finding — retrieval is *not* the main bottleneck

This **refines the v3 narrative.** Document-level retrieval is already
strong: the right article is in the top-5 for **70 of 74** questions,
and usually at rank 1 (MRR 0.89). Yet the generation benchmark sits at
≈ 81 %. So the bulk of the ≈ 14-point gap opens **after** good retrieval
— it is generation robustness (v3's own finding: llama over-refuses or
picks the wrong chunk from a good-but-noisy top-5), not gross retrieval
failure.

Two caveats keep this honest:

- **Document-level recall is lenient.** "Right article in top-5" does
  not guarantee the specific answer *passage* is the one shown. Real
  chunk-level precision is somewhat below 94.6 %.
- **The distance guard costs nothing here** (0 threshold drops): 0.65 is
  well calibrated — it is not silently hiding good chunks.

The practical consequence: **a cross-encoder reranker is no longer the
obvious first move.** With recall@5 at 94.6 %, a reranker can only
rescue the handful of cases below — useful, but not the headline lever.
The bigger lever for *answer* quality is the generator (and the multidoc
prompt), which the retrieval number now lets us isolate.

## The 4 retrieval failures, named

| Q | What | First relevant rank | Diagnosis |
|---|---|---|---|
| **B05** | "seule équipe française à gagner la LdC" → OM 1993 | **miss** (not in top-20) | The question never names OM; the vector drifts to generic "Ligue des champions" content. → query rewriting / hybrid (lexical) search. |
| **A23** | capacity of the Vélodrome | 16 (cut) | The capacity sits deep in the OM article; other *stadium* chunks outrank it. → chunk-level precision / reranking. |
| **H02** | which French clubs reached a C1 final | 14 | Multi-doc: needs 5 articles; the generic "Championnat" article ranks first. → multi-vector / per-entity retrieval. |
| **H08** | clubs with a stadium in Auvergne-Rhône-Alpes | **miss** | A *player* article (Bergougnoux) squats the top — the v3 case, confirmed. → reranking / metadata filter. |

Each now has an address (a phase, a fix), instead of a vague "improve
retrieval".

## What this unlocks

Every future retrieval change — reranking, hybrid BM25 + vector, query
rewriting, per-entity scoping — can now be scored against **recall@k and
MRR** before touching a generation model: a fast, deterministic, cheap
loop (no GPU, ~seconds). The rule from the 116-titles episode holds: a
fix is only proven when the metric moves.

## Limits

- Document-level, single relevant-set per question; multi-doc questions
  are scored as "any relevant in top-k", which under-penalises partial
  coverage (H02 would need all five).
- One embedding model, one corpus snapshot. The gold file must grow with
  the corpus.
- recall@5 is an **upper bound** on retrieval health (leniency above).
