# Answer quality

This document tracks the quality of the RAG's answers over time, using
the `rag_queries` log table (ADR-0011) as the audit source. First audit:
2026-06-11, over the 14 queries logged since the chat went live.

## Audit of 2026-06-11 (gemma3:4b, pre-improvements)

| Verdict | Count | Examples |
|---|---|---|
| ✅ Correct, sourced | 6 | Sonny Anderson's record at OL; Stade de Reims history; Parc OL capacity |
| ✅ Legitimate refusals (grounding works) | 2 | Player absent from corpus → *"le contexte ne contient pas cette information"* ; loaded question ("prove Mbappé is bad") → argued refusal |
| ⚠️ Partially wrong | 3 | OL founding: *"1896… créé en 1950"* (unarbitrated contradiction); *"OL plays at the Training Center ground"*; a description promoted to a "nickname" |
| ❌ Plainly wrong | 1 | *"OL previously played at **Geoffroy-Guichard**"* — Saint-Étienne's stadium (cross-entity confusion) |
| 🤖 Degraded behaviour | 1 | Slang input (*"tié trop le sang purée"*) → answer **in English** about RC Lens colours, after 100s of CPU |

## Failure taxonomy

1. **Cross-entity confusion (dominant).** Chunks lost their origin once
   isolated from their article: a derby passage mentioning
   Geoffroy-Guichard, or "the club founded in 1896" (Lyon OU, OL's
   ancestor), read as facts about OL. The chunker carried no article or
   section context.
2. **No arbitration by the small model.** Contradictory chunks are
   juxtaposed, not resolved; missing facts get improvised from loosely
   related text (a description became a "nickname").
3. **No input guard.** Nearest-neighbour search always returns k
   results, however irrelevant — junk input flowed through embedding,
   retrieval and 100s of generation, and the "answer in the question's
   language" rule broke on slang.

## Actions taken (2026-06-11)

| Action | ADR |
|---|---|
| Section-aware contextual chunking — every chunk prefixed with `[Article : X — Section : Y]`, embedded and shown to the model | [ADR-0013](adr/0013-section-aware-contextual-chunking.md) |
| Relevance threshold (cosine distance > 0.65 → chunk dropped; no relevant chunk → canned answer, generation skipped) | [ADR-0014](adr/0014-retrieval-relevance-threshold.md) |
| Hardened grounding prompt (explicit facts only, per-article arbitration, French by default) | [ADR-0014](adr/0014-retrieval-relevance-threshold.md) |
| Per-source cosine `distance` logged in `rag_queries.sources` for future calibration | [ADR-0014](adr/0014-retrieval-relevance-threshold.md) |

Calibration data behind the 0.65 threshold (best-chunk distance):

| Query | Distance |
|---|---|
| "Quelle est la capacité du Parc OL ?" | 0.41 |
| "Qui a fondé le FC Nantes ?" | 0.48 |
| "tié trop le sang purée" | 0.77 |
| "meilleure recette de tarte aux pommes ?" | 0.77 |
| "explique-moi la mécanique quantique" | 0.82 |

## Re-test after improvements (2026-06-11, corpus re-embedded: 2,659 chunks)

| Question | Before | After |
|---|---|---|
| OL home stadium | ❌ *"previously played at Geoffroy-Guichard"* (ASSE's stadium) | ✅ Groupama Stadium as the main answer, Gerland correctly in the past — the cross-entity confusion is gone |
| OL founding date | ⚠️ *"founded in 1896 … created in 1950"* (raw contradiction) | ✅ Coherent, arbitrated narrative: *"fondé en 1896 sous le nom de Racing Club de Lyon, puis en 1950 sous le nom d'Olympique lyonnais"* |
| Juninho's nickname | ⚠️ Description promoted to a "nickname" | ⚠️ **Still fabricated** — the model now quotes an even longer description as a "surnom". The prompt rule alone doesn't fix this on a 4B model |
| *"tié trop le sang purée"* | 🤖 100s of CPU, answer in English about RC Lens | ✅ Guard answer in **0.2s**, generation skipped (`provider: guard (out-of-corpus)`) |

**Score: 3 of 4 failure cases fixed.** The remaining one (soft
fabrication) is the failure mode attributed to model size in the
taxonomy above — the designated test is re-asking with
`--provider=claude` once an API key is available.

## Local model benchmark (2026-06-11) — superseded by the 50-question run

> ⚠️ **This 5-question benchmark was later invalidated**: the
> [50-question benchmark](04-benchmark-local-models.md) (ADR-0019)
> reversed its conclusion and made llama3.2:3b the default. Kept as a
> lesson on small-sample evaluation.

Generation on CPU felt slow (gemma3:4b). Four models compared on the
same 5 questions over the enriched corpus (141 docs / 4,966 chunks,
infoboxes included), all runs logged in `rag_queries`, warm models:

| Model | Size | Median gen. | Quality (of 5) | Notes |
|---|---|---|---|---|
| gemma3:4b (default) | 3.3GB | 12.4s | **4** | Only model to read the OM coach from the **infobox** (killer demo ✓); still fabricates a "nickname" |
| llama3.2:3b | 2.0GB | 9.9s | **4** | The only model to **pass the fabrication trap** ("aucun détail sur le surnom") — but botched the infobox question (answered 2023 history) |
| qwen3:1.7b (think off) | 1.4GB | 8.9s | 2.5 | Confident fabrications: invented nickname "Le Maréchal", added Ligue 2 titles to the OL count |
| gemma3:1b | 815MB | 5.1s | 2 | Fast but unusable: answered cup titles when asked about championships, vague hallucinated prose |

**Decision ([ADR-0016](adr/0016-keep-gemma3-4b-after-benchmark.md)):
keep gemma3:4b.** The 2.5s saved by llama3.2:3b don't pay for losing
the infobox-grounded current-facts answers, and smaller models trade
speed for confident fabrication — the worst failure mode for a RAG.
The actual latency levers are elsewhere: models pinned in memory
(`OLLAMA_KEEP_ALIVE=-1` — the 30-100s outliers were model reloads),
`RAG_TOP_K` to shrink prompt processing, and answer streaming for
perceived latency.

Side note: the same benchmark finally validated the **killer demo** —
*"Qui est l'entraîneur actuel de l'OM ?"* → *"Habib Beye (selon la
fiche infobox)"* — the structured-data extraction of ADR-0015 closed
the gap observed on day one.

## Candidate next steps (not implemented)

- Compare providers on the failed questions (`--provider=claude`) once
  an API key is configured — failure mode 2 is largely a model-size
  issue.
- Reranking (cross-encoder) of the top-k; query rewriting; adaptive k.
- Full "contextual retrieval": LLM-generated context sentence per chunk.
