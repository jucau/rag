# ADR-0011 — Log every RAG query with per-phase timings

**Status:** Accepted — 2026-06-10

## Context

`rag:ask` feels slow, and the pipeline has three candidate bottlenecks:
embedding the question (Ollama), the similarity search (pgvector), and
the generation (Ollama or Claude). Guessing is pointless — we want
numbers, kept over time, so behaviour can be compared across models,
corpus sizes and configuration changes.

## Decision

Instrument the online pipeline (`AnswerQuestion`) and persist one row
per query in a `rag_queries` table:

- `question`, `answer`, `provider`
- `sources` (JSON, one entry per retrieved chunk in ranking order:
  `{chunk_id, title, url}` — denormalised because chunks are rebuilt on
  re-ingestion, so bare chunk ids would go stale)
- `embedding_ms`, `retrieval_ms`, `generation_ms`, `total_ms`

`rag:ask` prints the same breakdown after each answer.

## Consequences

- "Where is it slow?" is answered by data, current and historical
  (`SELECT avg(generation_ms) … GROUP BY provider`).
- The log doubles as an evaluation dataset later: question + retrieved
  chunks + answer is exactly what RAG evaluation frameworks consume.
- Questions and answers are stored in clear text — fine for a local
  pedagogical project; a production system would consider retention and
  privacy.
