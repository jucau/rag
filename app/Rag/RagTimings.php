<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * Where the time of one RAG query went, in milliseconds.
 */
final readonly class RagTimings
{
    public function __construct(
        public int $embeddingMs,
        public int $retrievalMs,
        public int $generationMs,
    ) {
    }

    public function totalMs(): int
    {
        return $this->embeddingMs + $this->retrievalMs + $this->generationMs;
    }
}
