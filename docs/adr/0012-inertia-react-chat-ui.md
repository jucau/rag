# ADR-0012 — Inertia.js + React chat UI

**Status:** Accepted — 2026-06-10

## Context

The RAG was only usable through `rag:ask` in a terminal. A web chat
interface makes it usable (and demoable), and the owner wants to work
with Inertia.js + React specifically.

## Decision

- **Inertia.js** (`inertiajs/inertia-laravel` + `@inertiajs/react`):
  Laravel routes render React pages directly — no separate API
  authentication, no SPA router, one codebase.
- **React 19 + Vite + Tailwind 4** (the Laravel default toolchain plus
  `@vitejs/plugin-react`).
- A **`node` Compose service** (pinned `node:22-alpine`, the LTS) runs
  `npm install && npm run dev` — the Docker-only rule (ADR-0002) extends
  to frontend tooling. Vite's dev server listens on `0.0.0.0:5173` with
  HMR pointed at `localhost`; `make npm-build` produces static assets.
- The chat page lives on `/` (default templates removed). Asking a
  question is a plain JSON `POST /ask` (validated by `AskRequest`,
  served by `AskController` → `AnswerQuestion`): chat history is
  client-side state, which fits XHR better than full Inertia visits.
- The UI shows a typing-dots animation while generation runs, then the
  answer with its sources (deduplicated links) and provider/timing
  metadata. A provider selector switches Ollama/Claude per question.
- PHP's `max_execution_time` is raised to 120s in the app image: local
  generation regularly exceeds the 30s default.

## Consequences

- The full pipeline is now observable from a browser, including the
  latency (the typing indicator runs for the whole generation time —
  pedagogically honest).
- One more service and toolchain (node_modules, Vite) to maintain.
- `POST /ask` is synchronous: the HTTP request waits for the LLM. Fine
  here; a production chat would stream tokens (SSE) instead — a good
  future iteration.
