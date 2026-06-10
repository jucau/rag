<?php

declare(strict_types=1);

namespace App\Rag;

use App\Models\DocumentChunk;
use Illuminate\Support\Collection;

final readonly class RagAnswer
{
    /**
     * @param Collection<int, DocumentChunk> $chunks the retrieved context, for source attribution
     */
    public function __construct(
        public string $answer,
        public Collection $chunks,
        public string $provider,
        public RagTimings $timings,
    ) {
    }

    /**
     * One entry per retrieved chunk, in retrieval (ranking) order, with
     * the document it belongs to and its cosine distance to the question
     * (lower = more relevant; the threshold lives in rag.retrieval).
     *
     * @return list<array{chunk_id: int, title: string, url: string, distance: float}>
     */
    public function sources(): array
    {
        return array_values($this->chunks
            ->map(fn (DocumentChunk $chunk): array => [
                'chunk_id' => $chunk->id,
                'title' => $chunk->document->title,
                'url' => $chunk->document->url,
                'distance' => round($chunk->neighbor_distance, 4),
            ])
            ->all());
    }
}
