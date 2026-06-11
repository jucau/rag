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

        $startedAt = hrtime(true);
        $stats = ['ok' => 0, 'skipped' => 0, 'chunks' => 0, 'structured' => 0, 'no_wikitext' => 0];

        foreach ($titles as $title) {
            $page = $wikipedia->fetchPage($title);

            if (! $page instanceof WikipediaPage) {
                $this->warn(sprintf('⚠ "%s": page not found or empty, skipped.', $title));
                ++$stats['skipped'];

                continue;
            }

            $result = $this->ingestPage($page, $wikipedia, $extractor, $chunker, $embeddings);

            // The silent-failure lesson of 2026-06-11: make every step
            // measurable in the output. A wikitext that stops coming back
            // or chunk counts that stop moving must be visible live.
            $this->line(sprintf(
                '✔ %s — %d chunk(s)%s — %d structured section(s)',
                $page->title,
                $result['chunks'],
                $result['previous'] === null ? '' : sprintf(' (was %d)', $result['previous']),
                $result['structured'],
            ));

            if ($result['wikitext_missing']) {
                $this->warn(sprintf('⚠ %s: no wikitext returned — structured data skipped.', $page->title));
                ++$stats['no_wikitext'];
            }

            ++$stats['ok'];
            $stats['chunks'] += $result['chunks'];
            $stats['structured'] += $result['structured'];

            // Politeness towards the Wikimedia API between articles.
            Sleep::usleep(200_000);
        }

        $elapsed = (int) round((hrtime(true) - $startedAt) / 1_000_000_000);

        $this->newLine();
        $this->info(sprintf(
            'Done in %dm%02ds — %d article(s) ingested (%d skipped), %d chunks, %d structured sections, %d article(s) without wikitext.',
            intdiv($elapsed, 60),
            $elapsed % 60,
            $stats['ok'],
            $stats['skipped'],
            $stats['chunks'],
            $stats['structured'],
            $stats['no_wikitext'],
        ));

        if ($stats['ok'] > 0 && $stats['structured'] === 0) {
            $this->error('Sanity check: no structured section extracted on the whole run — the wikitext pipeline is probably broken (see ADR-0015).');

            return self::FAILURE;
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

    /**
     * @return array{chunks: int, previous: int|null, structured: int, wikitext_missing: bool}
     */
    private function ingestPage(
        WikipediaPage $page,
        WikipediaClient $wikipedia,
        WikitextStructuredExtractor $extractor,
        SectionAwareChunker $chunker,
        EmbeddingProvider $embeddings,
    ): array {
        // The prose comes from the clean plain-text extract; the infobox
        // and tables (stripped from extracts) are recovered from the raw
        // wikitext and appended as regular "== … ==" sections (ADR-0015).
        $content = $page->content;
        $wikitext = $wikipedia->fetchWikitext($page->title);
        $structured = $wikitext === null ? [] : $extractor->extract($wikitext);

        if ($structured !== []) {
            $content .= "\n\n" . implode("\n\n", $structured);
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

        // On a refresh, the previous chunk count is the reference the new
        // one is compared against in the output.
        $previous = $document->wasRecentlyCreated ? null : $document->chunks()->count();

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

        return [
            'chunks' => count($chunks),
            'previous' => $previous,
            'structured' => count($structured),
            'wikitext_missing' => $wikitext === null,
        ];
    }
}
