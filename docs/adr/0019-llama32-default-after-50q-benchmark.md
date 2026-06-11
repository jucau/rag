# ADR-0019 — llama3.2:3b becomes the default local model

**Status:** Accepted — 2026-06-11. **Supersedes ADR-0016** (which kept
gemma3:4b on the basis of a 5-question benchmark).

## Context

The 5-question benchmark behind ADR-0016 was too small. A 50-question
set (7 categories including fabrication traps, disambiguation and
out-of-corpus cases — versioned in `benchmark/questions.json`) was run
through the production pipeline on 4 models, 200 logged runs. Full
analysis: [docs/04-benchmark-local-models.md](../04-benchmark-local-models.md).

Key numbers:

| Model | Weighted /50 | Fabrications (❌) | Median gen. |
|---|---|---|---|
| llama3.2:3b | **42.0** | **1** | **9.0s** |
| gemma3:4b | 40.0 | 6 | 12.6s |
| qwen3:1.7b | 36.5 | 8 | 7.8s |
| gemma3:1b | 20.0 | 25 | 6.2s |

## Decision

Make **`llama3.2:3b`** the default chat model (config default and
factory fallback). gemma3:4b stays in the whitelist — it remains the
best model *when an answer exists* (most ✅, best at infobox reading)
and one click away in the UI.

Rationale: for a grounded assistant, **confident fabrication is the
worst failure mode**; llama3.2:3b's failure mode is benign
over-caution. It also runs 29% faster on median.

## Consequences

- Default answers become more trustworthy and faster; slightly more
  "je ne trouve pas cette information" on questions whose answer was
  actually retrievable (14 ⚠️, mostly over-caution).
- A02-style current-fact questions (coach from the infobox) are weaker
  on the new default — pipeline mitigation tracked separately (clearer
  table/infobox linearisation).
- Methodological rule recorded: **model selection decisions require
  the 50-question set, not spot checks** — `rag:benchmark` makes a
  full run a one-command operation (~35 min).
