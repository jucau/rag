# Local model benchmark — 50 questions × 4 models (2026-06-11)

The first benchmark (5 questions, [03-answer-quality.md](03-answer-quality.md))
was too small to be conclusive — and, as this larger run shows, it was
**misleading**. This document is the full analysis of the 200-run
benchmark.

## Protocol

- **Question set**: [benchmark/questions.json](../benchmark/questions.json) —
  50 questions in 7 categories, versioned in the repo, each with its
  expected ground truth. Run with `php artisan rag:benchmark`.
- **Pipeline**: every run goes through the exact production path
  (`AnswerQuestion`): same embeddings, same retrieval (top-5, threshold
  0.65), same prompt — only the generation model changes. All runs are
  logged in `rag_queries`.
- **Fairness**: per-model warm-up call (model loading excluded from
  timings), models pinned in memory, sequential blocks.
- **Grading** (by Claude, against the expected ground truth and the
  corpus): ✅ correct · ⚠️ partial/incomplete/over-cautious without
  factual error · ❌ wrong or fabricated · 🛡️ expected refusal or
  presupposition-correction delivered (includes guard hits).
- **Weighted score**: ✅ = 1, 🛡️ = 1, ⚠️ = 0.5, ❌ = 0.

## Headline results

| Model | Score /50 | ✅ | 🛡️ | ⚠️ | ❌ | Median gen. | Max |
|---|---|---|---|---|---|---|---|
| **llama3.2:3b** | **42.0** | 23 | **12** | 14 | **1** | **9.0s** | 67.8s |
| gemma3:4b | 40.0 | **28** | 8 | 8 | 6 | 12.6s | 18.8s |
| qwen3:1.7b (think off) | 36.5 | 21 | 10 | 11 | 8 | 7.8s | 14.4s |
| gemma3:1b | 20.0 | 13 | 2 | 10 | 25 | 6.2s | 27.0s |

The single most important column is **❌ (wrong/fabricated)**: a RAG
that fabricates with confidence is worse than one that abstains.
llama3.2:3b produced **one** wrong answer out of 50; gemma3:4b six;
gemma3:1b twenty-five.

## Per-category scores (weighted, normalised /10)

| Category (n) | gemma3:4b | gemma3:1b | qwen3:1.7b | llama3.2:3b |
|---|---|---|---|---|
| Factuel simple (12) | 7.9 | 6.3 | 7.9 | 7.1 |
| Factuel historique (10) | 8.5 | 3.5 | 7.0 | 8.5 |
| Synthèse (8) | 8.8 | 4.4 | 7.5 | 7.5 |
| Désambiguïsation (6) | 8.3 | 5.0 | 7.5 | 9.2 |
| **Pièges à fabrication (8)** | 6.3 | 0.0 | 7.5 | **9.4** |
| Hors corpus (4) | 7.5 | 5.0 | 10.0 | 10.0 |
| Questions biaisées (2) | 10.0 | 2.5 | 10.0 | 10.0 |

Profiles emerge clearly: **gemma3:4b wins when there is an answer to
find** (synthesis, simple facts), **llama3.2:3b wins when the right
move is to refuse, correct or arbitrate** (traps, disambiguation,
out-of-corpus). gemma3:1b is unusable (0/10 on traps — it fabricated
all eight, inventing nicknames with fake sources, a Pagis goal tally,
and "PSG won Roland-Garros 2025").

## Full matrix (✅ ⚠️ ❌ 🛡️)

| Q | gemma3:4b | gemma3:1b | qwen3:1.7b | llama3.2:3b |
|---|---|---|---|---|
| A01 capacité Parc OL | ✅ | ✅ | ✅ | ✅ |
| A02 entraîneur OM (infobox) | ✅ | ✅ | ❌ | ❌ |
| A03 fondation Nantes | ✅ | ✅ | ✅ | ✅ |
| A04 surnom Lens | ✅ | ✅ | ✅ | ✅ |
| A05 surnom ASSE | ⚠️ | ⚠️ | ✅ | ⚠️ |
| A06 stade LOSC | ✅ | ❌ | ✅ | ✅ |
| A07 surnom supporters OL | ❌ | ⚠️ | ⚠️ | ⚠️ |
| A08 président OL | ✅ | ❌ | ⚠️ | ⚠️ |
| A09 fondation PSG | ✅ | ✅ | ✅ | ⚠️ |
| A10 capacité Geoffroy-Guichard | ❌ | ❌ | ⚠️ | ⚠️ |
| A11 club de la Beaujoire | ✅ | ✅ | ✅ | ✅ |
| A12 ville Montpellier HSC | ✅ | ✅ | ✅ | ✅ |
| B01 titres OL masculins | ✅ | ❌ | ⚠️ | ⚠️ |
| B02 record de titres | ✅ | ❌ | ❌ | ✅ |
| B03 entraîneur Reims 50s | ✅ | ❌ | ✅ | ✅ |
| B04 LdC de l'OM | ❌ | ❌ | ❌ | ✅ |
| B05 équipe française vainqueure LdC | ⚠️ | ❌ | ❌ | ⚠️ |
| B06 meilleur buteur OL | ✅ | ✅ | ✅ | ✅ |
| B07 finale ASSE | ✅ | ❌ | ✅ | ✅ |
| B08 titres ASSE | ✅ | ✅ | ✅ | ✅ |
| B09 Anderson avant l'OL | ✅ | ✅ | ✅ | ✅ |
| B10 Brésilien coups francs | ✅ | ❌ | ✅ | ✅ |
| C01 Reims historique | ✅ | ⚠️ | ⚠️ | ⚠️ |
| C02 derby OL-ASSE | ✅ | ⚠️ | ✅ | ✅ |
| C03 épopée Verts 70s | ⚠️ | ❌ | ⚠️ | ⚠️ |
| C04 domination OL 2000s | ✅ | ❌ | ✅ | ⚠️ |
| C05 rôle d'Aulas | ✅ | ⚠️ | ⚠️ | ✅ |
| C06 place de Sochaux | ✅ | ⚠️ | ✅ | ✅ |
| C07 Bad Gones | ✅ | ⚠️ | ✅ | ✅ |
| C08 Paris FC | ⚠️ | ⚠️ | ⚠️ | ⚠️ |
| D01 titres OL féminines | ✅ | ✅ | ⚠️ | ✅ |
| D02 LdC OL Lyonnes | ✅ | ❌ | ✅ | ✅ |
| D03 masculine ou féminine | ⚠️ | ❌ | ❌ | ✅ |
| D04 Paris FC vs PSG | ✅ | ⚠️ | ✅ | ✅ |
| D05 plus ancien club | ⚠️ | ✅ | ✅ | ⚠️ |
| D06 stade du Stade de Reims | ✅ | ✅ | ❌ | ✅ |
| E01 surnom Juninho (piège) | ⚠️ | ❌ | 🛡️ | 🛡️ |
| E02 buts de Pagis (piège) | 🛡️ | ❌ | 🛡️ | 🛡️ |
| E03 Zidane à l'OL (faux présupposé) | ⚠️ | ❌ | ⚠️ | ⚠️ |
| E04 Mbappé entraîneur (faux présupposé) | ❌ | ❌ | ❌ | 🛡️ |
| E05 Vélodrome 2030 (piège) | 🛡️ | ❌ | 🛡️ | 🛡️ |
| E06 palmarès Lens en LdC (piège) | ❌ | ❌ | ⚠️ | ⚠️ |
| E07 gardien OL 2003 | ✅ | ❌ | ❌ | 🛡️ |
| E08 record brestois (faux présupposé) | 🛡️ | ❌ | 🛡️ | 🛡️ |
| F01 quiche lorraine | 🛡️ guard | 🛡️ guard | 🛡️ guard | 🛡️ guard |
| F02 Roland-Garros 2025 | 🛡️ | ❌ | 🛡️ | 🛡️ |
| F03 Pythagore | 🛡️ guard | 🛡️ guard | 🛡️ guard | 🛡️ guard |
| F04 Premier League 2024 | ❌ | ❌ | 🛡️ | 🛡️ |
| G01 OM surcoté | 🛡️ | ❌ | 🛡️ | 🛡️ |
| G02 arbitrage pro-PSG | 🛡️ | ⚠️ | 🛡️ | 🛡️ |

## Cross-model findings — pipeline issues, not model issues

The value of running 4 models on identical retrievals: **shared
failures point at the pipeline**.

1. **Shared retrieval miss (A07)**: no model could name "les Gones" —
   the chunk carrying the nickname never reached the top-5 after the
   contextual re-chunking. The models that "failed honestly" said so;
   gemma3:4b invented an answer from the chunks it got. → retrieval
   issue to investigate (chunk ranking for that section).
2. **Shared number misuse (B04, E06)**: gemma3:4b and qwen3 both
   hallucinated *"116 Ligues des champions"* for OM.

   > **Erratum (2026-06-12).** The initial diagnosis blamed the
   > linearised *"Bilan par compétition"* table. After expanding the
   > abbreviated table headers (strategy 2026-06-12.1) the error
   > persisted — inspection of the actual chunks showed the real
   > source: **"capital social de 116,5 millions d'euros"** in the
   > *Statut juridique* section, fused into a trophy count by the
   > model. This is fabrication-by-assembly (gemma3:4b's signature
   > failure mode), not a linearisation bug; the default model
   > (llama3.2:3b, ADR-0019) answers the same question correctly on
   > the same chunks. The header expansion remains in place as
   > hardening — E06-style rows ("Perdus : 6" instead of "P : 6") are
   > genuinely less ambiguous — and the lesson is methodological:
   > **verify a diagnosis against the retrieved chunks, not against
   > the model's own source citations** (models cite carelessly).
3. **Threshold blind spot (F02, F04)**: Roland-Garros and the Premier
   League pass the 0.65 distance gate (football-adjacent vocabulary)
   and reach the model. Three models refused properly; gemma3:1b made
   PSG win both competitions. → the guard cannot replace model-level
   grounding; both layers are needed.
4. **Infobox extraction is model-sensitive (A02)**: same infobox chunk
   in context; gemma3:4b/1b read "Habib Beye" correctly, qwen3 answered
   Gattuso *while citing the infobox*, llama drifted to Mandanda. The
   data was right; attention to it varies.

## Per-model profiles

- **llama3.2:3b — the trustworthy one.** One wrong answer in 50. Best
  on traps (9.4/10: the only model to correct "Mbappé n'est pas un
  entraîneur"), disambiguation and out-of-corpus. Weaknesses:
  over-caution (14 ⚠️ — refuses or hedges where the answer was in
  context, e.g. PSG's founding year "sources contradictoires"), and the
  one infobox miss (A02). 29% faster than gemma3:4b on median.
- **gemma3:4b — the productive one.** Most ✅ (28) — when an answer
  exists, it finds and words it best (synthesis 8.8/10), and it reads
  infoboxes reliably. But 6 confident fabrications ("Mbappé entraîne le
  PSG", "116 LdC", a PSG Premier League podium) — the failure mode that
  erodes trust fastest.
- **qwen3:1.7b — better than its reputation.** The 5-question sample
  (2.5/5) underrated it: strong on refusals (10 🛡️) and decent
  everywhere except source attribution (cites the infobox while
  contradicting it). Fastest among usable models.
- **gemma3:1b — disqualified.** Fabricated all 8 traps, invented fake
  sources, 25 wrong answers. Speed is irrelevant at this quality.

## Verdict

**llama3.2:3b becomes the default local model**
([ADR-0019](adr/0019-llama32-default-after-50q-benchmark.md),
superseding ADR-0016): best weighted score, an order of magnitude fewer
fabrications than gemma3:4b (1 vs 6), 29% faster on median. The known
trade-offs (more abstentions, one infobox miss) are the benign failure
mode for a grounded assistant.

The methodological lesson is recorded too: **the 5-question benchmark
had picked the other winner.** Small samples over-weight individual
questions (gemma3:4b's infobox win, qwen3's one invented nickname);
n=50 with categories and traps reversed the ranking.

Limits of this benchmark: single run per question (no variance
estimate), one grader, French-only corpus, and the question set is now
public to the models' future versions (overfitting risk if reused as-is
for model selection).
