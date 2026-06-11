# ADR-0020 — Split ingestion into fetch and index, with hash-based staleness

**Status:** Accepted — 2026-06-12. Replaces the single
`rag:ingest-wikipedia` command (fetch + chunk + embed in one pass).

## Context

Fetching (network, Wikimedia-rate-limited) and indexing (CPU,
chunking + embedding) were welded together: changing the chunking
strategy (ADR-0013) forced a full re-scrape of Wikipedia, and the table
linearisation fix identified by the benchmark (ADR-0019 findings) would
have required another one. The `documents.content` column was designed
to allow re-chunking without re-fetching — but no command exploited it,
and the structured sections (ADR-0015) were baked into `content` at
fetch time anyway.

## Decision

**Two commands, raw materials stored, staleness computed.**

- **`rag:fetch-wikipedia`** (network only): stores the *raw* materials —
  plain-text extract in `content`, raw `wikitext`, Wikipedia's
  `revision_id` (lastrevid), and a `content_hash` (sha256 over both).
  A light revision check (1 small API call) skips unchanged pages
  before downloading anything heavy — incremental refresh for free.
  Options: `--title`, `--category`, `--depth`, `--limit`, `--all`
  (re-check every stored document).
- **`rag:index`** (local only): assembles the indexable text (extract +
  linearised infobox/tables — the transformation now lives here),
  chunks, embeds, and records `chunked_at`, `chunked_content_hash` and
  `chunking_version`. By default it processes **only stale documents**;
  `--force` re-indexes everything.

A document is stale when (`Document::indexingReason()`):
- it has never been chunked; or
- `chunked_content_hash ≠ content_hash` (the page content changed); or
- `chunking_version ≠ DocumentIndexer::VERSION` (the strategy changed —
  the constant must be bumped with any chunker/extractor change).

Timestamps alone were rejected: a re-fetch that changes nothing would
bump `fetched_at` and trigger a useless (and expensive) re-embedding;
hashes are insensitive to no-op fetches.

## Consequences

- Chunking-strategy changes, linearisation fixes and embedding-model
  swaps become `rag:index --force` (~30 min, local) instead of a full
  re-scrape (~1h, network).
- Refreshing the corpus is incremental at both stages: unchanged
  revisions are skipped at fetch; unchanged hashes are skipped at index.
- ~50-60MB of raw wikitext stored in the database (acceptable).
- Discipline required: bump `DocumentIndexer::VERSION` whenever the
  chunker or extractor changes.
- Legacy rows (no hash) are simply considered stale and rebuilt on the
  first `rag:index` run.
