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
     * The position matches the "[Source N]" numbering of the generation
     * prompt, so an answer citing "Source 3" refers to position 3.
     * Chunk content is included on demand (UI display) but kept out of
     * the rag_queries log, where chunks would only duplicate data.
     *
     * @return list<array{position: int, chunk_id: int, title: string, url: string, distance: float, content?: string}>
     */
    public function sources(bool $withContent = false): array
    {
        return self::sourcesFromChunks($this->chunks, $withContent);
    }

    /**
     * @param Collection<int, DocumentChunk> $chunks
     * @return list<array{position: int, chunk_id: int, title: string, url: string, distance: float, content?: string}>
     */
    public static function sourcesFromChunks(Collection $chunks, bool $withContent = false): array
    {
        return array_values($chunks
            ->map(function (DocumentChunk $chunk, int $index) use ($withContent): array {
                $source = [
                    'position' => $index + 1,
                    'chunk_id' => $chunk->id,
                    'title' => $chunk->document->title,
                    'url' => $chunk->document->url,
                    'distance' => round($chunk->neighbor_distance, 4),
                ];

                if ($withContent) {
                    $source['content'] = $chunk->content;
                }

                return $source;
            })
            ->all());
    }
}
