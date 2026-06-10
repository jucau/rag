# ADR-0010 — Ollama as default generation provider, Claude as alternative

**Status:** Accepted — 2026-06-10. Amends ADR-0004 (which made Claude the
generation engine) following an explicit owner preference.

## Context

ADR-0004 chose Claude for generation on quality grounds — still true. But
this project is pedagogical, and running generation locally teaches
things an API cannot:

- what a small model (4B parameters) can and cannot do with the same
  retrieved context: weaker grounding, hallucinations, poorer French —
  observable side by side with Claude;
- the same provider/driver pattern as embeddings (ADR-0007), generalised;
- a fully offline, zero-cost, key-less default path: anyone can clone
  the project and ask questions without any API account.

## Decision

Define a `ChatProvider` contract with two implementations:

- **`OllamaChatProvider` — the default** (`RAG_CHAT_PROVIDER=ollama`),
  serving **`gemma3:4b`** (~3.3GB, good multilingual/French for its
  size, no special output quirks). Runs on CPU in the existing `ollama`
  service.
- **`ClaudeChatProvider` — the alternative**, using the official
  `anthropic-ai/sdk` and `claude-opus-4-8`. Requires `ANTHROPIC_API_KEY`.

The `rag:ask` command accepts `--provider=` to override the configured
default per call — the point being to **compare both answers on the same
retrieved context**.

## Consequences

- Default experience is free, private and offline; the frontier-quality
  path is one flag away.
- Local generation on CPU is slow (~5–15 tokens/s) and needs ~4GB RAM in
  the Docker VM — acceptable for a demo, unpleasant for daily use.
- Comparing providers on identical context makes the quality gap (and
  hallucinations) tangible — a core learning goal.
- Two providers to maintain; the contract keeps the surface minimal.
