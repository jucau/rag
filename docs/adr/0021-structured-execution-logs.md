# ADR-0021 — Structured execution logs for batch commands

**Status:** Accepted — 2026-06-12

## Context

Batch runs (fetch, index, benchmark) only produced console output —
ephemeral, unstructured, and lost on crash. Both 429 incidents left no
exploitable trace: no record of how far the run got, what was processed
and what wasn't. The `rag_queries` table (ADR-0011) solved this for the
question path; the batch path had nothing.

## Decision

Every batch command (`rag:fetch-wikipedia`, `rag:index`,
`rag:benchmark`) exports one JSON file per run to **`storage/rag-runs/`**
via the `ExecutionLog` service:

- run-level metadata: command, options, chunking-strategy version,
  start/end timestamps, duration, status;
- **per-item events** (one per page/document/benchmark-run) with
  timestamps and item-specific fields (revision id, staleness reason,
  chunk counts…);
- a **summary** with explicit counters (e.g. fetch distinguishes
  `created` / `refreshed` / `unchanged` / `not_found` /
  `without_wikitext`);
- **crash-safe**: `handle()` is wrapped in try/finally, so a failed run
  still writes its file with `status: failure`, the error message and
  every event accumulated before the crash.

Console lines and JSON events come from the same code path — no double
bookkeeping. The folder is gitignored (run artefacts, not source).

`rag:ask` is intentionally out of scope: its observability lives in the
`rag_queries` table, which is queryable and feeds the benchmark
analysis.

## Consequences

- Post-mortems become trivial (`jq` over the run files: durations,
  warning counts, failure points) and runs are comparable over time.
- A crashed ingestion now documents exactly where it stopped.
- Slight duplication between console output and events is structural —
  acceptable, they serve different consumers (human now / analysis
  later).
