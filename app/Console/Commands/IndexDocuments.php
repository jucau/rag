<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Document;
use App\Rag\Indexing\DocumentIndexer;
use Illuminate\Console\Command;

/**
 * The "Transform & Load" half of ingestion (ADR-0020): rebuilds chunks
 * and embeddings for the documents that need it — fully local, no
 * Wikipedia call involved.
 */
class IndexDocuments extends Command
{
    #[\Override]
    protected $signature = 'rag:index
        {--title=* : Restrict to specific document title(s)}
        {--force : Re-index every document, up to date or not}';

    #[\Override]
    protected $description = 'Chunk and embed stale documents (content changed, strategy changed, or never indexed)';

    public function handle(DocumentIndexer $indexer): int
    {
        $query = Document::query()->orderBy('id');

        /** @var list<string> $titles */
        $titles = $this->option('title');

        if ($titles !== []) {
            $query->whereIn('title', $titles);
        }

        $force = (bool) $this->option('force');

        $this->info(sprintf('Chunking strategy version: %s', DocumentIndexer::VERSION));

        $startedAt = hrtime(true);
        $stats = ['indexed' => 0, 'fresh' => 0, 'chunks' => 0];

        foreach ($query->lazy() as $document) {
            $reason = $force ? 'forced' : $document->indexingReason(DocumentIndexer::VERSION);

            if ($reason === null) {
                ++$stats['fresh'];

                continue;
            }

            $previous = $document->chunks()->count();
            $count = $indexer->index($document);

            $this->line(sprintf(
                '✔ %s — %d chunk(s)%s — %s',
                $document->title,
                $count,
                $previous > 0 ? sprintf(' (was %d)', $previous) : '',
                $reason,
            ));

            ++$stats['indexed'];
            $stats['chunks'] += $count;
        }

        $elapsed = (int) round((hrtime(true) - $startedAt) / 1_000_000_000);

        $this->newLine();
        $this->info(sprintf(
            'Done in %dm%02ds — %d document(s) re-indexed (%d chunks), %d already fresh.',
            intdiv($elapsed, 60),
            $elapsed % 60,
            $stats['indexed'],
            $stats['chunks'],
            $stats['fresh'],
        ));

        return self::SUCCESS;
    }
}
