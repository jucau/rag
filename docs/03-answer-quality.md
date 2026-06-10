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

## Candidate next steps (not implemented)

- Compare providers on the failed questions (`--provider=claude`) once
  an API key is configured — failure mode 2 is largely a model-size
  issue.
- Reranking (cross-encoder) of the top-k; query rewriting; adaptive k.
- Full "contextual retrieval": LLM-generated context sentence per chunk.
