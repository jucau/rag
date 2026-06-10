# ADR-0013 — Section-aware contextual chunking

**Status:** Accepted — 2026-06-11. Amends ADR-0009 (paragraph-aware
chunking, which remains the inner packing strategy).

## Context

The first answer-quality audit ([docs/03-answer-quality.md](../03-answer-quality.md))
showed that the dominant failure mode was **cross-entity confusion**:
chunks lose their origin once isolated from their article ("the stadium"
from a derby article became "a stadium related to OL"). The embedding of
an anonymous passage is also blurrier than it could be.

Wikipedia plain-text extracts conveniently keep their section headings
as `== Heading ==` lines — which our original chunker treated as noise.

## Decision

Add a `SectionAwareChunker` on top of `TextChunker`:

1. Split the article on `== … ==` heading lines; the text before the
   first heading is the introduction.
2. Pack each section's paragraphs with the existing `TextChunker`
   (1200 chars / 200 overlap, unchanged).
3. Prefix every chunk with its origin:
   `[Article : Olympique lyonnais — Section : Stades]`.

The prefix is part of the stored content, so it is **embedded with the
passage** (a chunk about "Stades" in the OL article now carries those
words in its vector) and **visible to the generation model** (the
hardened system prompt of ADR-0014 references it for arbitration).

Empty sections (headings whose content was a table stripped by the
extracts API) are skipped. Sub-headings simply become the current
section (no breadcrumb of parent headings — keep it simple).

## Consequences

- Chunks are self-describing: retrieval and generation both know which
  entity a passage talks about. Overlap no longer leaks across section
  boundaries (sections are chunked independently).
- **The whole corpus must be re-embedded** (vectors change with the
  prefixed text) — the `--refresh` option added to `rag:ingest-wikipedia`
  re-ingests every stored document.
- Chunk counts rise slightly (sections don't share chunks); tiny
  passages under lone headings produce small chunks.
- This is the lightweight cousin of "contextual retrieval" (where an
  LLM writes a context sentence per chunk); if quality still falls
  short, that is the next step up.
