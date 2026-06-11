<?php

declare(strict_types=1);

namespace App\Rag\Indexing;

use App\Models\Document;
use App\Rag\Chunking\SectionAwareChunker;
use App\Rag\Corpus\WikitextStructuredExtractor;
use App\Rag\Embeddings\EmbeddingProvider;

/**
 * The "Transform & Load" half of ingestion (ADR-0020): assembles the
 * indexable text from the stored raw materials, chunks it, embeds the
 * chunks and records what they were built from (hash + version).
 */
final readonly class DocumentIndexer
{
    /**
     * Identifies the chunking strategy as a whole (chunker + extractor
     * + prompt-visible chunk format). BUMP THIS whenever any of them
     * changes: rag:index then knows every document is stale.
     *
     * History:
     * - 2026-06-11.1 = section-aware contextual chunking (ADR-0013)
     *   + wikitext structured sections (ADR-0015).
     * - 2026-06-12.1 = table header abbreviations expanded ("M : 116" →
     *   "Matchs : 116") and infobox heading marked as current facts —
     *   fixes the "116 Ligues des champions" misread found by the
     *   benchmark (docs/04).
     */
    public const string VERSION = '2026-06-12.1';

    /**
     * How many chunks are embedded per Ollama call.
     */
    private const int EMBEDDING_BATCH_SIZE = 16;

    public function __construct(
        private SectionAwareChunker $chunker,
        private WikitextStructuredExtractor $extractor,
        private EmbeddingProvider $embeddings,
    ) {
    }

    /**
     * Rebuilds the document's chunks from its stored raw content,
     * timing the cheap phase (chunking) and the expensive one
     * (embedding) separately.
     */
    public function index(Document $document): IndexResult
    {
        // The prose comes from the stored plain-text extract; infobox
        // and tables are linearised from the stored wikitext — the whole
        // transformation is local, no network involved.
        $started = hrtime(true);
        $content = $document->content;

        if ($document->wikitext !== null) {
            $structured = $this->extractor->extract($document->wikitext);

            if ($structured !== []) {
                $content .= "\n\n" . implode("\n\n", $structured);
            }
        }

        $chunks = $this->chunker->chunk($document->title, $content);
        $chunkingMs = $this->elapsedMs($started);

        // Chunks are rebuilt from scratch: stale vectors must not
        // survive a content or strategy update.
        $document->chunks()->delete();

        $started = hrtime(true);
        $index = 0;

        foreach (array_chunk($chunks, self::EMBEDDING_BATCH_SIZE) as $batch) {
            $vectors = $this->embeddings->embedBatch($batch);

            foreach ($batch as $position => $chunkContent) {
                $document->chunks()->create([
                    'chunk_index' => $index,
                    'content' => $chunkContent,
                    'embedding' => $vectors[$position],
                ]);

                ++$index;
            }
        }

        $embeddingMs = $this->elapsedMs($started);

        $document->update([
            'chunked_at' => now(),
            'chunked_content_hash' => $document->content_hash,
            'chunking_version' => self::VERSION,
        ]);

        return new IndexResult(
            chunks: count($chunks),
            chunkingMs: $chunkingMs,
            embeddingMs: $embeddingMs,
        );
    }

    private function elapsedMs(int|float $startedNs): int
    {
        return (int) round((hrtime(true) - $startedNs) / 1_000_000);
    }
}
