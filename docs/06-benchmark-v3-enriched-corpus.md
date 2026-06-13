# Benchmark v3 — enriched + de-noised corpus (2026-06-13)

Same 103 questions ([benchmark/questions.json](../benchmark/questions.json))
as [v2](05-benchmark-103-questions.md), re-run after two changes:

1. **Corpus enriched**: 141 → 217 documents — missing OL players
   (Lloris, Fekir, Lacazette…), L1 legends (Platini, Zidane, Mbappé,
   Henry…), and L1 stats/records articles (ADR-0023 harvest).
2. **Corpus de-noised**: boilerplate end-matter sections dropped
   ([ADR-0024](adr/0024-drop-boilerplate-sections.md)) — ~4.5% fewer chunks.
3. Generation now on the **host GPU** ([ADR-0022](adr/0022-host-ollama-for-generation.md)).

412 runs, **0 errors, 17m06s** (vs 64m on CPU in v2 — **3.7× faster**
thanks to the GPU). Median generation: gemma3:4b 3.2s (was 12.4s),
qwen3 2.0s, llama 2.8s, gemma3:1b 0.9s.

## Headline: the two contenders converged

Carefully re-graded (full answer text, same rules as v2):

| Model | v2 /103 | **v3 /103** | Δ | Fabrications (❌) |
|---|---|---|---|---|
| llama3.2:3b | 89 (86%) | **~83 (81%)** | **−5** | ~8 |
| gemma3:4b | 78 (76%) | **~83 (81%)** | **+5** | ~12 |

**The enriched corpus closed the gap** — gemma3:4b went *up*, llama
went *down*, meeting near 81%. The richer corpus is not uniformly good:
it **favours the more capable model**.

## Why — the counter-intuitive finding

More documents → retrieval returns a **richer but noisier top-5** (a
question about a stadium now competes with the new player/legend
articles; both Gerland *and* Geoffroy-Guichard chunks can land in the
top-5). What the models do with that ambiguous context differs:

- **gemma3:4b sifts it well.** On the *same* retrieved context it now
  answers correctly where llama fails: A10 (Geoffroy-Guichard, not
  Gerland), A21 (Louis-Dreyfus 1996), H06 (Paris FC *and* PSG), A15
  (les Canaris), A20 (les Dogues). The extra data it couldn't reach in
  v2 is now usable.
- **llama3.2:3b — its caution turns costly.** The smaller model
  over-refuses answerable questions (A15, B02) or picks the wrong chunk
  from the diluted context (A10 → Gerland, A21 → 1994 coaching changes).
  Its v2 strength (refusing) now also misfires on a couple of traps it
  used to nail (E01, E10).

So llama's −5 is **not a model regression** (same model) — it is the
richer context exposing its lower robustness, exactly where gemma3:4b's
extra capacity pays off.

## Real wins unlocked by the expansion

- **I05** — *"Quel joueur de l'OL a gagné la CdM 2018 ?"* → gemma3:4b:
  **"Nabil Fekir et Corentin Tolisso"** ✅ (impossible in v2 — absent
  from corpus). llama still refuses (retrieval doesn't surface it for
  it — a robustness gap, not a data gap).
- **I07** — successor of Coupet → **Hugo Lloris** ✅ (both models).
- **A24** — current PSG coach → **Luis Enrique** ✅ (both, from infobox).
- **A23** — Vélodrome capacity → **67 394** ✅ (gemma; article now in corpus).
- **A08** — current OL president → reaches **Michele Kang** ✅ (both).
- **I03** — Lacazette → **Arsenal** ✅ (both).

## What still fails (backlog)

- **Shared retrieval misses, model-independent** (same wrong top-5 for
  everyone): A07 *les Gones* (the nickname chunk never ranks), H08
  *stades en AURA* (a player's article — Bergougnoux — outranks the
  clubs), B14 (Nantes' European best), H01 (OM/PSG confusion).
- **Conditional "which player did X" queries** (I05 for llama): the
  fact exists but vector search doesn't surface it from the right
  article. → reranking or query rewriting.
- **gemma3:4b still fabricates on traps** (~12 ❌: invents a Juninho
  nickname, a Doumbia record, an OM-PSG final score). This is why
  llama keeps the default crown despite the tie (see below).

## Verdict — llama3.2:3b stays the default

The scores tied, but [ADR-0019](adr/0019-llama32-default-after-50q-benchmark.md)'s
principle holds: **for a grounded assistant, fewer confident
fabrications wins**. llama still fabricates less (~8 vs ~12 ❌) and is
faster. gemma3:4b is the better pick when *coverage* matters more than
*caution* — and the UI keeps it one click away.

The actionable lesson is about **retrieval, not the model**: the
de-noising (ADR-0024) helped, but the bigger corpus now needs better
*ranking* to pay off for the weaker model too. Candidate next steps:
a cross-encoder reranker, a higher `top_k` with reranking, query
rewriting for conditional questions, or per-entity scoping.

## Limits

Single run per question, one grader, snippet-assisted grading (±3 pts
of noise — treat the scores as "both ≈ 81%", not exact). The weak two
models were not re-graded in full: gemma3:1b stays bottom (it still
invents people), qwen3:1.7b mid-pack (good refusals, wild
entity cross-wiring) — neither challenges the ranking.
