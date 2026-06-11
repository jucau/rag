<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Document;
use App\Rag\Corpus\WikipediaClient;
use App\Rag\Corpus\WikipediaPage;
use App\Rag\Corpus\WikipediaRevision;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * The "Extract" half of ingestion (ADR-0020): scrapes Wikipedia and
 * stores the raw materials (plain-text extract, wikitext, revision id,
 * content hash). No chunking, no embedding — that's rag:index.
 */
class FetchWikipedia extends Command
{
    #[\Override]
    protected $signature = 'rag:fetch-wikipedia
        {--title=* : Article title(s) to fetch}
        {--category=* : Category name(s) whose pages will all be fetched (e.g. "Catégorie:…")}
        {--depth=0 : How many levels of subcategories to walk for --category}
        {--limit=0 : Safety cap on the total number of articles (0 = no cap)}
        {--all : Re-check every document already stored (incremental: unchanged revisions are skipped)}';

    #[\Override]
    protected $description = 'Fetch Wikipedia articles and store their raw content (no chunking — see rag:index)';

    public function handle(WikipediaClient $wikipedia): int
    {
        $titles = $this->collectTitles($wikipedia);

        $limit = (int) $this->option('limit');

        if ($limit > 0 && count($titles) > $limit) {
            $this->warn(sprintf('Capping at --limit=%d articles (%d collected).', $limit, count($titles)));
            $titles = array_slice($titles, 0, $limit);
        }

        if ($titles === []) {
            $this->error('Nothing to fetch: pass at least one --title or --category, or use --all.');

            return self::FAILURE;
        }

        $this->info(sprintf('Fetching %d article(s)…', count($titles)));

        $startedAt = hrtime(true);
        $stats = ['fetched' => 0, 'unchanged' => 0, 'skipped' => 0];

        foreach ($titles as $title) {
            $existing = Document::query()
                ->where('source', 'wikipedia')
                ->where('title', $title)
                ->first();

            // Light revision check first: one small API call instead of
            // two heavy ones when the page did not change.
            if ($existing?->revision_id !== null) {
                $latest = $wikipedia->latestRevisionId($title);

                if ($latest !== null && $latest === $existing->revision_id) {
                    $this->line(sprintf('– %s — unchanged (rev %d), skipped.', $title, $latest));
                    ++$stats['unchanged'];

                    continue;
                }
            }

            $page = $wikipedia->fetchPage($title);
            $revision = $page instanceof WikipediaPage ? $wikipedia->fetchRevision($page->title) : null;

            if (! $page instanceof WikipediaPage) {
                $this->warn(sprintf('⚠ "%s": page not found or empty, skipped.', $title));
                ++$stats['skipped'];

                continue;
            }

            $contentHash = hash('sha256', $page->content . "\0" . ($revision->wikitext ?? ''));

            $document = Document::query()->updateOrCreate(
                ['source' => 'wikipedia', 'external_id' => (string) $page->pageId],
                [
                    'title' => $page->title,
                    'url' => $page->url,
                    'content' => $page->content,
                    'wikitext' => $revision->wikitext ?? null,
                    'revision_id' => $revision->revisionId ?? null,
                    'content_hash' => $contentHash,
                    'fetched_at' => now(),
                ],
            );

            $this->line(sprintf(
                '✔ %s — rev %s%s%s',
                $page->title,
                $revision->revisionId ?? '?',
                $document->wasRecentlyCreated ? ' (new)' : '',
                $revision instanceof WikipediaRevision ? '' : ' — ⚠ no wikitext',
            ));

            ++$stats['fetched'];

            // Politeness towards the Wikimedia API between articles.
            Sleep::usleep(200_000);
        }

        $elapsed = (int) round((hrtime(true) - $startedAt) / 1_000_000_000);

        $this->newLine();
        $this->info(sprintf(
            'Done in %dm%02ds — %d fetched, %d unchanged, %d skipped. Run rag:index to (re)build stale chunks.',
            intdiv($elapsed, 60),
            $elapsed % 60,
            $stats['fetched'],
            $stats['unchanged'],
            $stats['skipped'],
        ));

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

        if ((bool) $this->option('all')) {
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
}
