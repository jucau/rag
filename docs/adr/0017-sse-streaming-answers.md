# ADR-0017 — Stream answers over SSE

**Status:** Accepted — 2026-06-11

## Context

Local generation takes ~8-15s (ADR-0016) and the chat UI showed nothing
but a typing animation for that whole time. Streaming doesn't make
generation faster, but **perceived latency becomes "time to first
token"** (~1s): the answer grows on screen while the model writes.

## Decision

- `ChatProvider` gains `chatStream(): Generator<string>` — Ollama reads
  the NDJSON stream of `/api/chat`, Claude iterates the SDK's
  `createStream()` text deltas.
- `AnswerQuestion::askStream()` emits typed events
  (`RagStreamEventType` enum): `sources` right after retrieval, one
  `delta` per fragment, `done` with provider and timings. The query is
  logged to `rag_queries` once fully generated — same observability as
  the blocking path.
- `POST /ask-stream` renders them as **Server-Sent Events**
  (`text/event-stream`, flushed per event). The frontend consumes the
  stream with `fetch` + `ReadableStream` (native `EventSource` only
  supports GET — manual SSE parsing is the standard pattern for POST).
- `POST /ask` (blocking JSON) remains for CLI-style consumers and tests.
- `PHP_CLI_SERVER_WORKERS=4` on the app service: `artisan serve` is
  single-worker, and a streaming response would otherwise block every
  other request for its duration.

## Consequences

- Perceived latency drops from ~12s to ~1s; the real duration stays
  visible (timings in the `done` event, displayed under the answer).
- The HTTP connection stays open for the whole generation — fine
  locally; production would need proxy buffering disabled
  (`X-Accel-Buffering: no` is already sent) and sensible timeouts.
- Two code paths (blocking/streaming) share retrieval, prompt, guard
  and logging — only the transport differs.
