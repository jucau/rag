<?php

declare(strict_types=1);

namespace App\Rag\Indexing;

/**
 * What one document (re)indexing produced and cost — chunking is
 * CPU-trivial, embedding is the expensive phase worth watching.
 */
final readonly class IndexResult
{
    public function __construct(
        public int $chunks,
        public int $chunkingMs,
        public int $embeddingMs,
    ) {
    }

    public function totalMs(): int
    {
        return $this->chunkingMs + $this->embeddingMs;
    }
}
