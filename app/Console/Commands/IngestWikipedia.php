<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Document;
use App\Rag\Chunking\SectionAwareChunker;
use App\Rag\Corpus\WikipediaClient;
use App\Rag\Corpus\WikipediaPage;
use App\Rag\Corpus\WikitextStructuredExtractor;
use App\Rag\Embeddings\EmbeddingProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * The ingestion phase of the RAG pipeline (docs/02-rag-pipeline.md):
 * fetch Wikipedia articles → chunk them → embed each chunk → store
 * chunks + vectors in pgvector.
 */
class IngestWikipedia extends Command
{
    /**
     * How many chunks are embedded per Ollama call.
     */
    private const int EMBEDDING_BATCH_SIZE = 16;

    #[\Override]
    protected $signature = 'rag:ingest-wikipedia
        {--title=* : Article title(s) to ingest}
        {--category=* : Category name(s) whose pages will all be ingested (e.g. "Catégorie:…")}
        {--refresh : Re-ingest every document already in the database (re-fetch, re-chunk, re-embed)}
        {--depth=0 : How many levels of subcategories to walk for --category}
        {--limit=0 : Safety cap on the total number of articles (0 = no cap)}';

    #[\Override]
    protected $description = 'Fetch Wikipedia articles, chunk them, embed the chunks and store them';

    public function handle(
        WikipediaClient $wikipedia,
        SectionAwareChunker $chunker,
        WikitextStructuredExtractor $extractor,
        EmbeddingProvider $embeddings,
    ): int {
        $titles = $this->collectTitles($wikipedia);

        $limit = (int) $this->option('limit');

        if ($limit > 0 && count($titles) > $limit) {
            $this->warn(sprintf('Capping at --limit=%d articles (%d collected).', $limit, count($titles)));
            $titles = array_slice($titles, 0, $limit);
        }

        if ($titles === []) {
            $this->error('Nothing to ingest: pass at least one --title or --category, or use --refresh.');

            return self::FAILURE;
        }

        $this->info(sprintf('Ingesting %d article(s)…', count($titles)));

        foreach ($titles as $title) {
            $page = $wikipedia->fetchPage($title);

            if (! $page instanceof WikipediaPage) {
                $this->warn(sprintf('— "%s": page not found or empty, skipped.', $title));

                continue;
            }

            $chunkCount = $this->ingestPage($page, $wikipedia, $extractor, $chunker, $embeddings);

            $this->line(sprintf('✔ %s — %d chunk(s)', $page->title, $chunkCount));

            // Politeness towards the Wikimedia API between articles.
            Sleep::usleep(200_000);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function collectTitles(WikipediaClient $wikipedia): array
    {
        /** @var list<string> $titles */
        $titles = $this->option('title');

        /** @var list<string> $categories */
        $categories = $this->option('category');

        if ((bool) $this->option('refresh')) {
            // Embeddings depend on the chunking strategy and the model:
            // whenever either changes, the whole corpus must be rebuilt.
            /** @var list<string> $existing */
            $existing = Document::query()->where('source', 'wikipedia')->pluck('title')->all();
            $titles = array_merge($titles, $existing);
        }

        $depth = (int) $this->option('depth');

        foreach ($categories as $category) {
            $members = $wikipedia->categoryMembers($category, $depth);
            $this->info(sprintf('Category "%s": %d page(s).', $category, count($members)));

            $titles = array_merge($titles, $members);
        }

        return array_values(array_unique($titles));
    }

    private function ingestPage(
        WikipediaPage $page,
        WikipediaClient $wikipedia,
        WikitextStructuredExtractor $extractor,
        SectionAwareChunker $chunker,
        EmbeddingProvider $embeddings,
    ): int {
        // The prose comes from the clean plain-text extract; the infobox
        // and tables (stripped from extracts) are recovered from the raw
        // wikitext and appended as regular "== … ==" sections (ADR-0015).
        $content = $page->content;
        $wikitext = $wikipedia->fetchWikitext($page->title);

        if ($wikitext !== null) {
            $structured = $extractor->extract($wikitext);

            if ($structured !== []) {
                $content .= "\n\n" . implode("\n\n", $structured);
            }
        }

        // Idempotent by (source, external_id): re-ingesting refreshes.
        $document = Document::query()->updateOrCreate(
            ['source' => 'wikipedia', 'external_id' => (string) $page->pageId],
            [
                'title' => $page->title,
                'url' => $page->url,
                'content' => $content,
                'fetched_at' => now(),
            ],
        );

        // Chunks are rebuilt from scratch: stale vectors must not survive
        // a content update.
        $document->chunks()->delete();

        $chunks = $chunker->chunk($page->title, $content);
        $index = 0;

        foreach (array_chunk($chunks, self::EMBEDDING_BATCH_SIZE) as $batch) {
            $vectors = $embeddings->embedBatch($batch);

            foreach ($batch as $position => $content) {
                $document->chunks()->create([
                    'chunk_index' => $index,
                    'content' => $content,
                    'embedding' => $vectors[$position],
                ]);

                ++$index;
            }
        }

        return count($chunks);
    }
}
