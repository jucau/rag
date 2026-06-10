# ADR-0007 — EmbeddingProvider contract (driver pattern)

**Status:** Accepted — 2026-06-10

## Context

The application needs embeddings in several places (ingestion, query
time). Coupling that code directly to the Ollama HTTP API would make the
provider choice (ADR-0006) expensive to revisit, and would make tests
depend on a running Ollama.

## Decision

Define an `EmbeddingProvider` interface
(`app/Rag/Embeddings/EmbeddingProvider.php`):

```php
interface EmbeddingProvider
{
    /** @return list<float> */
    public function embed(string $text): array;

    /** @param list<string> $texts  @return list<list<float>> */
    public function embedBatch(array $texts): array;

    public function dimensions(): int;
}
```

`OllamaEmbeddingProvider` is the only implementation for now. The contract
is bound as a singleton in `AppServiceProvider`, configured from
`config/rag.php` — the same "driver" pattern Laravel uses for mailers,
queues and filesystem disks. Application code type-hints the interface,
never the implementation.

The implementation validates that returned vectors match the configured
dimension, failing fast on model/schema mismatches (see ADR-0006).

## Consequences

- Swapping or adding a provider (Voyage AI, OpenAI…) = one new class +
  one config change; no application code touched.
- Tests can bind a fake provider in the container — no Ollama needed.
- One more abstraction layer to understand — acceptable, since teaching
  this exact pattern is part of the project's pedagogical goal.
