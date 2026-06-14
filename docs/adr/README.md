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
| [0013](0013-section-aware-contextual-chunking.md) | Section-aware contextual chunking | Accepted |
| [0014](0014-retrieval-relevance-threshold.md) | Retrieval relevance threshold and hardened grounding prompt | Accepted |
| [0015](0015-structured-data-from-wikitext.md) | Recover structured data (infobox, tables) from wikitext | Accepted |
| [0016](0016-keep-gemma3-4b-after-benchmark.md) | Keep gemma3:4b for local generation; pin models in memory | Superseded by 0019 |
| [0017](0017-sse-streaming-answers.md) | Stream answers over SSE | Accepted |
| [0018](0018-nginx-php-fpm.md) | nginx + PHP-FPM instead of php artisan serve | Accepted |
| [0019](0019-llama32-default-after-50q-benchmark.md) | llama3.2:3b becomes the default local model | Accepted |
| [0020](0020-split-fetch-and-index.md) | Split ingestion into fetch and index, with hash-based staleness | Accepted |
| [0021](0021-structured-execution-logs.md) | Structured execution logs for batch commands | Accepted |
| [0022](0022-host-ollama-for-generation.md) | Host-native Ollama for generation (Metal GPU) | Accepted |
| [0023](0023-host-ollama-for-embeddings.md) | Host-native Ollama for embeddings too (Metal GPU) | Accepted |
| [0024](0024-drop-boilerplate-sections.md) | Drop boilerplate end-matter sections at chunking | Accepted |
| [0025](0025-measure-retrieval-separately.md) | Measure retrieval quality separately (recall@k, MRR) | Accepted |
