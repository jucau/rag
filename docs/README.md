# Documentation

This project is **pedagogical**: its primary goal is to understand how a RAG
(Retrieval-Augmented Generation) system works by building one from scratch
with Laravel. The documentation is a first-class citizen — every concept
encountered along the way is explained here, and every structural decision
is recorded.

## Contents

| Document | What you will find |
|---|---|
| [Project overview](01-project-overview.md) | The goal, the architecture and why each piece exists |
| [The RAG pipeline](02-rag-pipeline.md) | The concepts: ingestion, chunking, embeddings, retrieval, generation |
| [Answer quality](03-answer-quality.md) | Audits of real answers, failure taxonomy, improvements |
| [Local model benchmark](04-benchmark-local-models.md) | The 50-question × 4-model evaluation and its full analysis |
| [Glossary](glossary.md) | Every key concept explained in a few sentences |
| [Architecture Decision Records](adr/README.md) | Why we chose Docker, pgvector, Ollama, Claude, etc. |

## House rules for this documentation

- **Explanations live here, not only in chat.** Whenever a concept is
  explained during a working session, it must end up in one of these
  documents (usually `02-rag-pipeline.md` or the glossary).
- **Every structural choice gets an ADR** — see [adr/README.md](adr/README.md).
- **The glossary is maintained continuously**: any new term used in the
  code or the docs gets an entry.
- Everything is written in **English**.

For day-to-day development commands and conventions, see [LLM.md](../LLM.md)
at the repository root.
