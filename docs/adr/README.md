# Architecture Decision Records

An **ADR** records one structural decision: the context in which it was
taken, the decision itself, and its consequences (good and bad). ADRs are
immutable — if a decision changes, a new ADR supersedes the old one.

**Rule for this project: every structural choice gets an ADR**, written at
the time the choice is made.

## Index

| # | Decision | Status |
|---|---|---|
| [0001](0001-record-architecture-decisions.md) | Record architecture decisions | Accepted |
| [0002](0002-docker-only-development-environment.md) | Docker-only development environment | Accepted |
| [0003](0003-postgresql-pgvector-as-vector-store.md) | PostgreSQL + pgvector as the vector store | Accepted |
| [0004](0004-claude-for-answer-generation.md) | Claude (Anthropic API) for answer generation | Accepted |
| [0005](0005-code-quality-tooling-from-reference-project.md) | Code quality tooling mirrored from the reference project | Accepted |
| [0006](0006-local-embeddings-with-ollama.md) | Local embeddings with Ollama (embeddinggemma) | Accepted |
| [0007](0007-embedding-provider-contract.md) | EmbeddingProvider contract (driver pattern) | Accepted |
| [0008](0008-wikipedia-football-corpus.md) | French Wikipedia football articles as the test corpus | Accepted |
| [0009](0009-paragraph-aware-chunking-with-overlap.md) | Paragraph-aware chunking with overlap | Accepted |
| [0010](0010-ollama-default-generation-claude-alternative.md) | Ollama as default generation provider, Claude as alternative | Accepted |
| [0011](0011-log-rag-queries-with-timings.md) | Log every RAG query with per-phase timings | Accepted |
| [0012](0012-inertia-react-chat-ui.md) | Inertia.js + React chat UI | Accepted |
