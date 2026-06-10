# ADR-0008 — French Wikipedia football articles as the test corpus

**Status:** Accepted — 2026-06-10

## Context

The project needs a real corpus to ingest: open-licensed, large enough to
be meaningful, **recent** (to demonstrate RAG's core value: answering
about events past the model's training cutoff), and on a topic the author
knows well enough to judge answer quality. The author is a football
enthusiast.

Options considered:

- **French Wikipedia** (CC BY-SA): continuously updated prose — season
  articles are edited after every matchday — fetchable per article
  through the MediaWiki API.
- **openfootball** (public domain): maintained raw results, but terse
  structured text, less interesting to search semantically.
- **StatsBomb Open Data** (free, non-commercial): event-level JSON
  (passes, shots) — statistics, not prose; wrong shape for a text RAG.
- **Sports APIs** (football-data.org…): restrictive terms, not open data.

## Decision

Use **French Wikipedia football articles** as the primary corpus: current
season articles, clubs, players and competitions, fetched through the
MediaWiki API (`prop=extracts` for plain text) by the ingestion command —
no multi-GB dumps involved.

To comply with CC BY-SA attribution (and because it is good RAG practice
anyway), every stored document keeps its **source URL**, allowing answers
to cite their sources.

openfootball is kept as a possible secondary corpus to test the pipeline
on a different format.

## Consequences

- The "killer demo" becomes possible: a question about last weekend's
  results fails on a bare LLM (training cutoff) and succeeds with RAG.
- French prose validates the multilingual embedding model choice
  (ADR-0006).
- Corpus freshness depends on re-running ingestion; documents must be
  re-fetchable idempotently (upsert by source + external id).
- Wikipedia prose is encyclopedic and well-structured — easier than
  arbitrary PDFs; a deliberately gentle first corpus.
