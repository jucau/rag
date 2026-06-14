# Instrumenting generation, and how lenient was the retrieval metric? (2026-06-14)

[docs/07](07-retrieval-metrics.md) measured retrieval and found recall@5
= 94.6 %. Two questions followed:

1. **Where does the pipeline actually lose its points**, if the right
   document is almost always retrieved? → instrument *generation*
   ([ADR-0026](adr/0026-instrument-generation.md)).
2. **Is recall@5 inflated** by judging at the *document* level rather
   than the *chunk* level? (The right article may be retrieved through a
   chunk that talks about something else.) → add a **fact-level** recall.

Both use deterministic, judge-free grading (`AnswerGrader` +
`benchmark/answer-keys.json`): an answer/chunk is a hit if it contains
an accepted alias, normalised for case/accents/punctuation/digit
grouping and token-bounded ("vert" ≠ "couvert", "59 186" = "59186").

## 1. Generation, conditioned on retrieval

`rag:generation-eval` runs the real pipeline, then crosses *was the
answer in the context shown?* (a retrieved chunk belongs to the gold
document) with *what did the model output?* (correct / refusal / wrong).
The headline is the **good-context** bucket — pure generation skill,
retrieval held constant.

| model | good-ctx conversion | over-refusal | misread | trap refusal (caution) |
|---|---|---|---|---|
| llama3.2:3b | 59/70 (84 %) | **1** | **10** | **20/29 (69 %)** |
| gemma3:4b | 61/70 (87 %) | **0** | **9** | 10/29 (34 %) |

(Audit of the misreads found one grader false-negative — D02 answered
"8 fois", an alias we had missed — so llama is really ≈ 60/70. Treat
these as ±2.)

### The finding overturns a v3 hypothesis

v3 *guessed* that llama's weakness was **over-refusal** (refusing
answerable questions). Measured deterministically, llama over-refuses
exactly **once in 70**. The dominant good-context failure for *both*
models is **misread**: a wrong answer although the fact was in the
prompt — the model grabs a *competing* fact from the context:

- **A14** — OM founded 1899 → llama answers **"1897, sous le nom de
  Football Club de Marseille"** (a real but different date in the same
  article).
- **B15** — Monaco's 8 titles → **"cinq titres"**.
- **A10** — Geoffroy-Guichard capacity → **"35 616"** (a stale figure
  competing with the right one in a messy chunk).
- **A02** — current OM coach → **"Gattuso"** instead of the infobox value.

So the levers are **not** "make the model less shy". They are
**chunk-level precision** (don't hand the model a competing wrong
figure) and **the prompt** (disambiguate which fact answers the
question). Reranking helps here too — by surfacing the *fact-bearing*
chunk, not just the right article.

### Where llama earns the default crown

Not in conversion (gemma converts slightly *more*), but in **trap
discipline**: on the 29 fabrication-trap / off-corpus / biased
questions, llama refuses **69 %** vs gemma's **34 %**. gemma answers more
traps — and that is where its extra fabrications come from (v3's ~12 vs
~8 ❌). For a grounded assistant, that caution is the right tie-breaker
([ADR-0019](adr/0019-llama32-default-after-50q-benchmark.md)).
(Caveat: "trap refusal" counts refusals only; some trap answers are
correct *corrections* — the trap bucket measures caution, not full
correctness. v3's manual grading remains the trap-correctness authority.)

## 2. Was recall@5 lenient? — fact-level recall

`rag:retrieval-eval` now reports recall at two granularities:
**document-level** (right *article* retrieved) and **fact-level** (a
retrieved chunk's *content* states the answer).

| metric | document-level | fact-level | Δ pts |
|---|---|---|---|
| recall@1 | 85.1 % | **67.6 %** | **−17.6** |
| recall@3 | 93.2 % | 89.2 % | −4.0 |
| **recall@5** | **94.6 %** | **94.6 %** | **0.0** |
| recall@10 | 94.6 % | 97.3 % | (+2.7, see caveat) |
| MRR | 0.894 | 0.786 | |

**The concern was right — at rank 1.** The top chunk has the right
*article* 85 % of the time but actually *states the answer* only 68 % of
the time: a 17.6-point leniency. The #1 result is often the right
article through the wrong passage.

**But at the production top-5 the gap is exactly zero.** Whenever the
right article is in the top-5, one of those five chunks also carries the
fact (0 questions fail this). So **recall@5 = 94.6 % is not inflated** —
the leniency the owner flagged is real at rank 1 and **vanishes by k=5**.
Its true cost is in *ranking*, visible as the MRR drop (0.894 → 0.786):
the fact-bearing chunk is frequently not ranked first even when its
article is. **That is a reranking signal** — and it explains the
generation *misreads* above (the model sees the right article but the
competing chunk outranks the fact-bearing one).

### Caveat on the fact-level metric

Matching answer *aliases* against chunk content is itself noisy: generic
tokens ("1993", "Marseille") can match a chunk that merely mentions them
without answering the question — which is why fact-level recall@10
spuriously *exceeds* document-level. So fact-level is **indicative, not
exact**; document-level (upper bound) and fact-level (noisy lower-ish
bound) **bracket** the true chunk-level recall. A precise figure would
need chunk-id gold, deliberately avoided (chunk ids are unstable across
re-chunking; titles are not — ADR-0025).

## Synthesis — where the pipeline really stands

- **Retrieval is not the bottleneck**, and recall@5 holds up under the
  stricter fact-level check. Remaining retrieval work is *ranking*
  (reranking to lift the fact-bearing chunk), not coverage.
- **Generation's main loss is misread, not over-refusal.** Both models
  convert good context at ~85 %; the wrong ~15 % is competing-fact
  confusion → chunk precision + prompt, helped by reranking.
- **llama stays default for the measured right reason**: trap discipline
  (69 % vs 34 % refusal), i.e. fewer fabrications — not because it
  converts better.

Net: the single highest-leverage change for *answer* quality is a
**reranker** — but for a new reason. Not to raise recall (already 94.6 %)
but to raise **MRR / chunk precision**, which attacks the misread bucket
*and* the rank-1 leniency at once. That is now a measured hypothesis,
testable against recall@1-fact and the misread count.

## Limits

- Deterministic grader: lenient on aliases (false negatives, e.g. D02)
  and finite refusal-marker list. Full answers are logged for audit; the
  numbers are ±2.
- Single run per question (model sampling varies). Two contenders only
  (gemma3:1b / qwen3:1.7b unchanged from v3).
- Multi-doc questions graded as "any expected token present".
