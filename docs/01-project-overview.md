# Project overview

## Goal

Build a small but complete **RAG** (Retrieval-Augmented Generation) system
with Laravel, to understand every moving part: how documents are prepared,
how "meaning" is turned into numbers, how relevant passages are found, and
how a large language model uses them to answer questions.

The end result will be: *ask a question about your own documents, get an
answer grounded in those documents* — something a bare LLM cannot do,
because it knows nothing about your private data.

## Why RAG at all?

An LLM only knows what was in its training data. It does not know your
documents, and its knowledge has a cutoff date. Two naive workarounds fail:

- **Fine-tuning** a model on your documents is expensive, slow, and needs
  to be redone every time a document changes.
- **Pasting all documents into the prompt** does not scale: context windows
  are limited and large prompts are slow and costly.

RAG solves this differently: store the documents *outside* the model, find
the few passages relevant to the question at query time, and inject only
those into the prompt. The model stays generic; the knowledge stays fresh.

## Architecture

```
              INGESTION (offline)
┌──────────┐   ┌──────────┐   ┌────────────────┐   ┌──────────────────┐
│ Documents │ → │ Chunking │ → │ Embeddings     │ → │ PostgreSQL       │
└──────────┘   └──────────┘   │ (Ollama, local)│   │ + pgvector       │
                              └────────────────┘   └──────────────────┘

              QUERY TIME (online)
┌──────────┐   ┌────────────────┐   ┌──────────────────┐   ┌────────────────┐
│ Question  │ → │ Embedding      │ → │ Similarity search │ → │ Claude          │
└──────────┘   │ (same model!)  │   │ (top-k chunks)    │   │ (generation)    │
               └────────────────┘   └──────────────────┘   └────────────────┘
```

## The stack, and why each piece

| Piece | Choice | Why (short version — full reasoning in the ADRs) |
|---|---|---|
| Framework | Laravel 13 | The framework the author works with daily |
| Vector store | PostgreSQL 17 + pgvector | Vector search in plain SQL, works with Eloquent — no new database to learn ([ADR-0003](adr/0003-postgresql-pgvector-as-vector-store.md)) |
| Embeddings | Ollama + `embeddinggemma`, local | Small models are excellent at this; free, private, and shows how local inference is wired ([ADR-0006](adr/0006-local-embeddings-with-ollama.md)) |
| Generation | Claude (Anthropic API) | Answer quality requires a frontier model; small local models fall short ([ADR-0004](adr/0004-claude-for-answer-generation.md)) |
| Environment | Docker only | Nothing installed on the host ([ADR-0002](adr/0002-docker-only-development-environment.md)) |

### The hybrid pattern: local embeddings + API generation

This split is a very common production architecture, not just a learning
convenience:

- **Embeddings are called constantly** — once per chunk at ingestion, once
  per question at query time. They are cheap to compute (models of 100M–600M
  parameters run fine on CPU) but expensive to pay per-call at volume.
  Hosting them locally cuts cost and latency, and keeps document content
  private.
- **Generation is called once per question** and benefits enormously from a
  frontier model (reasoning, synthesis, good French). That is worth an API
  call.

### A note on Docker and GPUs (macOS)

Docker on macOS runs in a VM and has **no access to the Apple Metal GPU**.
For embeddings this is irrelevant — CPU inference is fast enough. If this
project ever did local *generation*, running Ollama natively on the host
(with Metal) would be the escape hatch — at the cost of the Docker-only rule.

## Services

| Service | Image | Port | Role |
|---|---|---|---|
| `app` | PHP 8.5 (Alpine) | 8000 | Laravel application |
| `db` | `pgvector/pgvector:pg17` | 5432 | Relational data + vector store |
| `ollama` | `ollama/ollama` | 11434 | Local embedding & generation inference |
| `node` | `node:22-alpine` | 5173 | Vite dev server (chat UI build) |
