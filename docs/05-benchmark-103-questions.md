# Local model benchmark v2 — 103 questions × 4 models (2026-06-12)

Second-generation benchmark, doubling the [50-question run](04-benchmark-local-models.md)
and adding two categories. Run on a freshly rebooted machine, zero
crashes, **412/412 runs in 63m46s**, fully streamed to the live JSONL
log (`rag:benchmark`, ADR-0021).

## Protocol changes vs v1

- **103 questions** ([benchmark/questions.json](../benchmark/questions.json)):
  the 50 calibrated v1 questions plus 53 new ones, including two new
  categories — **complexe-multidoc** (cross-club comparisons requiring
  multiple articles) and **joueurs** (exploiting the 100 harvested OL
  player articles) — plus 8 extra fabrication traps, 10 history
  questions and 4 out-of-corpus borderline cases.
- **Live observability**: every run streams `{model, question,
  expected, answer, timings}` to the `.jsonl` file as it happens.
- **Failure-resilient**: individual run errors are logged and skipped
  (lesson from the v1 re-run crash: one degenerate qwen3 generation
  spun 16m31s and killed everything; the crash-safe log saved the 221
  completed runs).
- Same grading rules as v1 (✅ 1 · 🛡️ 1 · ⚠️ 0.5 · ❌ 0, single grader).

## Headline results

| Model | Score /103 | % | ✅ | 🛡️ | ⚠️ | ❌ | Median gen. |
|---|---|---|---|---|---|---|---|
| **llama3.2:3b** | **89.0** | **86%** | 50 | **26** | 26 | **1** | 8.6s |
| gemma3:4b | 78.0 | 76% | **57** | 13 | 16 | 17 | 12.4s |
| qwen3:1.7b (think off) | 72.5 | 70% | 49 | 18 | 11 | 25 | 7.7s |
| gemma3:1b | 43.0 | 42% | 29 | 4 | 20 | 50 | 5.7s |

**The harder the set, the bigger llama's lead** (v1: +2 points; v2:
+11). Its fabrication count is *still* **one single wrong answer out
of 103** — the same one as v1 (the OM-coach infobox miss). gemma3:4b
remains the best at *finding* answers (57 ✅, top scores on simple and
historical facts) but produced **17 confident fabrications**.

## Per-category scores (weighted, /10)

| Category (n) | gemma3:4b | gemma3:1b | qwen3:1.7b | llama3.2:3b |
|---|---|---|---|---|
| Factuel simple (24) | **8.3** | 6.3 | 7.7 | 7.7 |
| Factuel historique (20) | **9.0** | 4.5 | 7.5 | 8.8 |
| Synthèse (8) | 8.1 | 4.4 | 9.4 | **10.0** |
| Désambiguïsation (6) | **9.2** | 6.7 | 6.7 | 7.5 |
| Pièges à fabrication (16) | 6.3 | 1.3 | 4.7 | **9.1** |
| Hors corpus (8) | 6.9 | 2.5 | 8.8 | **10.0** |
| Questions biaisées (5) | 6.0 | 3.0 | 8.0 | **10.0** |
| Complexe multi-doc (8) | 5.6 | 2.5 | 6.9 | **7.5** |
| Joueurs (8) | 6.3 | 5.0 | 4.4 | **8.8** |

llama3.2:3b takes 6 categories of 9, with **perfect scores on
out-of-corpus, loaded questions and synthesis**; gemma3:4b keeps the
fact-lookup categories. The two new categories are the hardest for
everyone — multi-document reasoning tops out at 7.5/10.

## Reproducibility check (gemma3:4b, v1 questions re-run)

Same model, same questions, fresh machine vs loaded machine: weighted
score 73.3% → 75.7%, median generation 12.6s → 12.4s. Grading noise is
roughly ±2 points — comfortably below the 10-point gaps that decide
the ranking.

## Cross-model findings

1. **A02 (OM coach, infobox) is structural**: in both benchmarks,
   gemma3:4b/1b read "Habib Beye" from the infobox correctly while
   qwen3 and llama answer Gattuso from historical prose. It is
   llama's *only* outright error — and the price of its prudence
   profile. Mitigation candidates: boost infobox chunks at retrieval,
   or a prompt nudge on "actuel" questions.
2. **A07 ("les Gones") is still a retrieval miss** for all four models
   — the chunk never reaches the top-5. Pipeline backlog.
3. **The "joueurs" category partly measured corpus coverage**: the
   alphabetical 100-player harvest stopped before F/L, so Fekir and
   Lloris have no articles. llama refused honestly (credited);
   gemma3:4b asserted "aucun joueur n'a gagné la CdM 2018" and
   "Lopes a succédé à Coupet" (fabricated negatives/successions);
   qwen3 invented "Mbappé champion du monde avec l'OL". → harvest the
   remaining players, then re-test.
4. **Expected-answer calibration cuts both ways**: on E16 (Ballons
   d'Or at OL) the grader's expected answer ("none") was wrong —
   gemma3:4b correctly surfaced Ada Hegerberg's 2018 Ballon d'Or with
   OL Lyonnes. Graded in its favour; a reminder that the corpus
   includes the women's section even when the question author forgets.
5. **qwen3's failure mode is confident cross-wiring**: "Zlatan a joué
   à l'OL entre 2011 et 2013", "Lacazette à l'Atlético", "Le Guen
   gardien titulaire" — wrong entity-attribute bindings stated with
   sources. Its refusals are good (18 🛡️) but its commitments are
   wild (25 ❌).
6. **gemma3:1b invents people**: "Benny Cabaye", coach "Thierry
   Laurey au PSG", "Réveillère gardien" — 50 ❌, definitively
   disqualified at any speed.

## Full matrix (✅ ⚠️ ❌ 🛡️ — order: gemma3:4b · gemma3:1b · qwen3 · llama3.2)

| Q | g3:4b | g3:1b | qwen3 | llama |
|---|---|---|---|---|
| A01 capacité Parc OL | ✅ | ✅ | ✅ | ✅ |
| A02 entraîneur OM (infobox) | ✅ | ✅ | ❌ | ❌ |
| A03 fondation Nantes | ✅ | ✅ | ✅ | ✅ |
| A04 surnom Lens | ✅ | ⚠️ | ✅ | ✅ |
| A05 surnom ASSE | ⚠️ | ✅ | ✅ | ⚠️ |
| A06 stade LOSC | ✅ | ❌ | ✅ | ✅ |
| A07 surnom supporters OL | ❌ | ⚠️ | ⚠️ | ⚠️ |
| A08 président OL | ✅ | ❌ | ⚠️ | ⚠️ |
| A09 fondation PSG | ✅ | ✅ | ✅ | ⚠️ |
| A10 capacité Geoffroy-Guichard | ❌ | ✅ | ⚠️ | ⚠️ |
| A11 club de la Beaujoire | ✅ | ⚠️ | ✅ | ✅ |
| A12 ville Montpellier HSC | ✅ | ✅ | ✅ | ✅ |
| A13 stade Monaco | ✅ | ✅ | ✅ | ✅ |
| A14 fondation OM | ⚠️ | ⚠️ | ⚠️ | ⚠️ |
| A15 surnom Nantes | ✅ | ✅ | ✅ | ✅ |
| A16 surnom Montpellier | ✅ | ✅ | ✅ | ✅ |
| A17 stade Strasbourg | ✅ | ❌ | ✅ | ✅ |
| A18 couleur ASSE | ✅ | ⚠️ | ✅ | ✅ |
| A19 stade Brest | ✅ | ❌ | ❌ | ⚠️ |
| A20 les Dogues | ✅ | ✅ | ✅ | ✅ |
| A21 OM décembre 1996 | ✅ | ❌ | ✅ | ✅ |
| A22 stade Auxerre | ✅ | ✅ | ✅ | ✅ |
| A23 capacité Vélodrome | ⚠️ | ⚠️ | ⚠️ | ⚠️ |
| A24 entraîneur PSG (infobox) | ⚠️ | ❌ | ❌ | ⚠️ |
| B01 titres OL masculins | ✅ | ❌ | ✅ | ✅ |
| B02 record de titres | ✅ | ❌ | ❌ | ✅ |
| B03 entraîneur Reims 50s | ✅ | ❌ | ✅ | ✅ |
| B04 LdC de l'OM | ✅ | ❌ | ❌ | ⚠️ |
| B05 équipe française vainqueure LdC | ❌ | ❌ | ✅ | ⚠️ |
| B06 meilleur buteur OL | ✅ | ✅ | ✅ | ✅ |
| B07 finale ASSE 1976 | ✅ | ✅ | ✅ | ✅ |
| B08 titres ASSE | ✅ | ✅ | ✅ | ✅ |
| B09 Anderson avant l'OL | ✅ | ✅ | ✅ | ✅ |
| B10 Brésilien coups francs | ✅ | ❌ | ✅ | ✅ |
| B11 président OM 1993 | ✅ | ✅ | ✅ | ✅ |
| B12 Lens champion | ✅ | ✅ | ✅ | ✅ |
| B13 Montpellier champion | ✅ | ✅ | ✅ | ⚠️ |
| B14 meilleure perf européenne Nantes | ❌ | ❌ | ❌ | ⚠️ |
| B15 titres Monaco | ✅ | ❌ | ❌ | ⚠️ |
| B16 finale Monaco 2004 | ✅ | ❌ | ✅ | ✅ |
| B17 entraîneur OM 1993 | ✅ | ✅ | ❌ | ✅ |
| B18 Reims-Real 1956 | ✅ | ❌ | ✅ | ✅ |
| B19 deux finales contre le Real | ✅ | ❌ | ✅ | ✅ |
| B20 scandale 1993 | ✅ | ✅ | ✅ | ✅ |
| C01 Reims historique | ✅ | ⚠️ | ✅ | ✅ |
| C02 derby OL-ASSE | ✅ | ⚠️ | ✅ | ✅ |
| C03 épopée Verts | ⚠️ | ⚠️ | ✅ | ✅ |
| C04 domination OL 2000s | ⚠️ | ❌ | ✅ | ✅ |
| C05 rôle d'Aulas | ✅ | ⚠️ | ✅ | ✅ |
| C06 place de Sochaux | ✅ | ⚠️ | ✅ | ✅ |
| C07 Bad Gones | ✅ | ⚠️ | ✅ | ✅ |
| C08 Paris FC | ⚠️ | ⚠️ | ⚠️ | ✅ |
| D01 titres OL féminines | ✅ | ✅ | ⚠️ | ✅ |
| D02 LdC OL Lyonnes | ✅ | ❌ | ✅ | ⚠️ |
| D03 masculine ou féminine | ✅ | ⚠️ | ⚠️ | ⚠️ |
| D04 Paris FC vs PSG | ✅ | ⚠️ | ✅ | ✅ |
| D05 plus ancien club | ⚠️ | ✅ | ✅ | ⚠️ |
| D06 stade du Stade de Reims | ✅ | ✅ | ❌ | ✅ |
| E01 surnom Juninho | ❌ | ❌ | 🛡️ | 🛡️ |
| E02 buts de Pagis | 🛡️ | ❌ | 🛡️ | 🛡️ |
| E03 Zidane à l'OL | ⚠️ | ❌ | ❌ | 🛡️ |
| E04 Mbappé entraîneur | ❌ | ⚠️ | ❌ | 🛡️ |
| E05 Vélodrome 2030 | 🛡️ | ❌ | 🛡️ | 🛡️ |
| E06 palmarès Lens LdC | ⚠️ | ❌ | ⚠️ | ⚠️ |
| E07 gardien OL 2003 | ✅ | ❌ | ❌ | ✅ |
| E08 record brestois | 🛡️ | ❌ | 🛡️ | 🛡️ |
| E09 « victoire » ASSE 1976 | 🛡️ | ❌ | ❌ | 🛡️ |
| E10 Coupes du monde des clubs OL | ❌ | ❌ | ❌ | ⚠️ |
| E11 finale OM-PSG | ⚠️ | ❌ | ❌ | ⚠️ |
| E12 FC Lyon-Villeurbanne | ❌ | ❌ | 🛡️ | 🛡️ |
| E13 Zlatan à l'OL | 🛡️ | ⚠️ | ❌ | 🛡️ |
| E14 record Meinau 2026 | ⚠️ | ❌ | ❌ | 🛡️ |
| E15 Nantes à Bordeaux | 🛡️ | 🛡️ | 🛡️ | 🛡️ |
| E16 Ballons d'Or à l'OL | ✅ | ❌ | 🛡️ | 🛡️ |
| F01 quiche lorraine | 🛡️ | 🛡️ | 🛡️ | 🛡️ |
| F02 Roland-Garros | 🛡️ | ❌ | 🛡️ | 🛡️ |
| F03 Pythagore | 🛡️ | 🛡️ | 🛡️ | 🛡️ |
| F04 Premier League | 🛡️ | ❌ | 🛡️ | 🛡️ |
| F05 Tour de France | ⚠️ | ❌ | 🛡️ | 🛡️ |
| F06 NBA | 🛡️ | ❌ | 🛡️ | 🛡️ |
| F07 Top 14 | ❌ | ❌ | ❌ | 🛡️ |
| F08 Serie A | 🛡️ | ❌ | 🛡️ | 🛡️ |
| G01 OM surcoté | ⚠️ | ❌ | 🛡️ | 🛡️ |
| G02 arbitrage pro-PSG | 🛡️ | ⚠️ | 🛡️ | 🛡️ |
| G03 arbitre voleur 1976 | 🛡️ | 🛡️ | 🛡️ | 🛡️ |
| G04 fidélité des supporters | ❌ | ❌ | 🛡️ | 🛡️ |
| G05 titres achetés | ⚠️ | ❌ | ❌ | 🛡️ |
| H01 OM ou PSG premier en LdC | ❌ | ❌ | ❌ | ⚠️ |
| H02 finalistes français de LdC | ✅ | ⚠️ | ✅ | ✅ |
| H03 OL vs ASSE titres | ✅ | ❌ | ✅ | ✅ |
| H04 Vélodrome vs Parc OL | ✅ | ❌ | ⚠️ | ✅ |
| H05 départ de Gerland | ⚠️ | ✅ | ❌ | ⚠️ |
| H06 deux clubs parisiens | ✅ | ⚠️ | ✅ | ✅ |
| H07 Reims vs Sochaux | ❌ | ❌ | ✅ | ⚠️ |
| H08 stades en AURA | ❌ | ❌ | ✅ | ⚠️ |
| I01 poste de Coupet | ✅ | ❌ | ❌ | ✅ |
| I02 formation de Benzema | ✅ | ✅ | ✅ | ✅ |
| I03 Lacazette en 2017 | ✅ | ✅ | ❌ | ✅ |
| I04 nationalité de Juninho | ✅ | ✅ | ✅ | ✅ |
| I05 champion du monde 2018 | ❌ | ❌ | ❌ | 🛡️ |
| I06 poste de Cris | ✅ | ✅ | ✅ | ✅ |
| I07 successeur de Coupet | ❌ | ❌ | ⚠️ | ⚠️ |
| I08 buts d'Anderson | ❌ | ❌ | ❌ | ⚠️ |

## Verdict

**ADR-0019 confirmed and amplified**: llama3.2:3b stays the default —
86% weighted, a single wrong answer in 103, perfect behaviour on every
adversarial category, and still ~30% faster than gemma3:4b. Its known
cost is over-caution (26 ⚠️, refusing answers the context contained)
and the A02 infobox blind spot.

Pipeline backlog fed by this run: harvest the remaining OL players,
fix the "les Gones" retrieval miss, and consider an infobox boost for
"actuel/actuelle" questions.

Limits: single grader, single run per question (the gemma3:4b
reproducibility check suggests ±2 points of noise), public question
set (overfitting risk for future model selections).
