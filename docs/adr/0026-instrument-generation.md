# ADR-0026 — Instrument generation, conditioned on retrieval

**Status:** Accepted — 2026-06-14. Builds on
[ADR-0025](0025-measure-retrieval-separately.md) (retrieval metric).

## Context

ADR-0025 measured retrieval alone: the answer document reaches the top-k
for ≈ 95 % of questions (recall@5 = 94.6 %, MRR = 0.89). Yet the answer
benchmark sits at ≈ 81 %. The missing points must therefore be lost in
**generation**, not retrieval — but "generation is the bottleneck" was
again an inference, not a measurement. We need to instrument the
generator the same way we instrumented retrieval, so improvements
(prompt, model, decoding) are scored, not guessed.

The hard part is a **correctness signal**. Earlier benchmarks graded
answers by hand / snippet assist (±3 pts of noise, not reproducible). An
LLM judge is the obvious automation, but ADR-0024 rejected small local
models as judges (they fabricate and misjudge) and no Claude API key is
configured. We want a signal that is **deterministic, reproducible and
auditable** today.

## Decision

Add `rag:generation-eval`, which runs the real pipeline and crosses two
deterministic signals per question:

1. **Context quality** — did a retrieved chunk (what the model actually
   saw) belong to the question's gold document
   (`benchmark/retrieval-gold.json`)? If yes, the answer *was* in the
   prompt.
2. **Outcome** — graded by `AnswerGrader`, a judge-free grader:
   - **correct** if the answer contains one of the accepted aliases in
     `benchmark/answer-keys.json` (normalised for case, accents,
     punctuation and digit grouping; token-bounded so "vert" ≠
     "couvert", "59 186" = "59186");
   - else **refusal** if it matches a refusal marker ("le contexte ne
     mentionne pas…");
   - else **wrong**.
   Correctness wins over refusal (a hedged-but-correct answer counts).

Crossing the two yields the instrument's headline — the **good-context**
bucket:

| | correct | refusal | wrong |
|---|---|---|---|
| **context good** | ✅ conversion | 🙈 **over-refusal** | ❌ **misread** |
| context poor | (retrieval-limited) | | |

**Over-refusal** (refused although the answer was in the prompt) and
**misread** (wrong although it was there) are *pure generation failures*
— the exact modes v3 suspected for llama, now counted. Trap / off-corpus
/ biased questions (no answer key) are reported separately as a
**refusal-discipline** rate (caution: higher is better).

The answer keys are a curated, versioned artefact covering exactly the
74 questions judged by retrieval-gold (a test ties the two files
together). `AnswerGrader` is unit-tested on its tricky cases (accents,
digit grouping, sub-word boundaries, hedged answers).

## Consequences

- Generation can now be optimised against numbers: conversion,
  over-refusal and misread per model, separated from retrieval. Comparing
  models (llama vs gemma) on the *same* good context quantifies the v3
  "caution vs capacity" trade-off instead of describing it.
- Deterministic and cheap: no judge, no API key, reproducible across
  runs (modulo model sampling). When a Claude key lands, an LLM judge can
  be added as a second, cross-checking grader — but is no longer a
  prerequisite to measure.
- The grader is **lenient by construction**: alias matching can mark a
  rambling answer "correct" if it contains the key token, and the
  refusal-marker list is finite. The full answer text is logged for every
  question, so disagreements are auditable and the keys/markers can be
  tuned against evidence (same discipline as the 116-titles fix).
- `answer-keys.json` is maintenance: new questions need keys, and a key
  that is too generic causes false positives. The test guards coverage
  and non-emptiness, not semantic correctness — that stays human.
- Multi-doc questions (H02/H06/H08) keep the lenient "any expected token"
  convention: a partial answer can score correct. Noted, not corrected —
  multi-fact grading is a later refinement if it matters.
