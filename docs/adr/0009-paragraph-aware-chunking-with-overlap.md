# ADR-0009 — Paragraph-aware chunking with overlap

**Status:** Accepted — 2026-06-10

## Context

Chunking is the most empirical part of a RAG (see
[the pipeline doc](../02-rag-pipeline.md)): chunk size and boundaries
directly drive retrieval quality. Strategies range from naive fixed-size
windows to semantic chunking (using a model to find topic boundaries).
Our corpus is encyclopedic prose (Wikipedia), where paragraphs are
already good semantic units.

## Decision

Implement a simple, readable strategy in `app/Rag/Chunking/TextChunker.php`:

- **Pack whole paragraphs** into chunks up to **1200 characters**
  (~300 tokens). Cutting mid-sentence produces chunks whose embedding
  represents half an idea; paragraphs avoid that.
- **Overlap of 200 characters**: each new chunk starts with the tail of
  the previous one, so an idea sitting astride two chunks is fully
  present in at least one of them.
- Paragraphs longer than a whole chunk are **hard-split at word
  boundaries**.

Defaults are constructor parameters, so they can be tuned per corpus.

## Consequences

- Deterministic, dependency-free, unit-testable chunking — and the
  algorithm stays simple enough to read in one sitting (a pedagogical
  requirement).
- Character counts approximate token counts (~4 chars/token in French);
  good enough here, a real tokenizer would be more precise.
- Wikipedia section titles are currently treated as small paragraphs —
  a future refinement could chunk per section and prepend the section
  title to each chunk (helps retrieval; revisit if quality disappoints).
- Tuning chunk size/overlap = re-running ingestion (embeddings change),
  but no schema impact.
