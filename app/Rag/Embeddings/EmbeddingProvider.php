<?php

declare(strict_types=1);

namespace App\Rag\Embeddings;

/**
 * Turns text into vectors (embeddings).
 *
 * The same provider (and underlying model) must be used at ingestion time
 * and at query time: vectors produced by different models live in different
 * vector spaces and are never comparable.
 */
interface EmbeddingProvider
{
    /**
     * @return list<float>
     */
    public function embed(string $text): array;

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embedBatch(array $texts): array;

    /**
     * Size of the vectors produced by this provider. Must match the
     * dimension of the vector column in the database.
     */
    public function dimensions(): int;
}
