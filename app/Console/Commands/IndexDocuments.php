<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Document;
use App\Rag\Indexing\DocumentIndexer;
use App\Rag\Observability\ExecutionLog;
use Illuminate\Console\Command;
use Throwable;

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

    /**
     * @var array<string, int>
     */
    private array $stats = ['indexed' => 0, 'fresh' => 0, 'chunks' => 0, 'chunking_ms' => 0, 'embedding_ms' => 0];

    public function handle(DocumentIndexer $indexer): int
    {
        $log = new ExecutionLog('rag:index', [
            'titles' => $this->option('title'),
            'force' => (bool) $this->option('force'),
        ]);

        try {
            $exit = $this->performRun($indexer, $log);
            $path = $log->finish($exit === self::SUCCESS ? 'success' : 'failure', $this->stats);
        } catch (Throwable $throwable) {
            // Crash-safe (ADR-0021): the log keeps every event recorded
            // before the failure, plus the error itself.
            $log->finish('failure', $this->stats, $throwable->getMessage());

            throw $throwable;
        }

        $this->line(sprintf('<comment>Execution log:</comment> %s', $path));

        return $exit;
    }

    private function performRun(DocumentIndexer $indexer, ExecutionLog $log): int
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

        foreach ($query->lazy() as $document) {
            $reason = $force ? 'forced' : $document->indexingReason(DocumentIndexer::VERSION);

            if ($reason === null) {
                ++$this->stats['fresh'];

                continue;
            }

            $previous = $document->chunks()->count();
            $result = $indexer->index($document);

            $this->line(sprintf(
                '✔ %s — %d chunk(s)%s — %s — %.1fs (embedding %.1fs)',
                $document->title,
                $result->chunks,
                $previous > 0 ? sprintf(' (was %d)', $previous) : '',
                $reason,
                $result->totalMs() / 1000,
                $result->embeddingMs / 1000,
            ));
            $log->event('indexed', [
                'title' => $document->title,
                'reason' => $reason,
                'chunks' => $result->chunks,
                'previous_chunks' => $previous,
                'chunking_ms' => $result->chunkingMs,
                'embedding_ms' => $result->embeddingMs,
                'duration_ms' => $result->totalMs(),
            ]);

            ++$this->stats['indexed'];
            $this->stats['chunks'] += $result->chunks;
            $this->stats['chunking_ms'] += $result->chunkingMs;
            $this->stats['embedding_ms'] += $result->embeddingMs;
        }

        $elapsed = (int) round((hrtime(true) - $startedAt) / 1_000_000_000);

        $this->newLine();
        $this->info(sprintf(
            'Done in %dm%02ds — %d document(s) re-indexed (%d chunks, embedding %dm%02ds, ~%dms/chunk), %d already fresh.',
            intdiv($elapsed, 60),
            $elapsed % 60,
            $this->stats['indexed'],
            $this->stats['chunks'],
            intdiv((int) round($this->stats['embedding_ms'] / 1000), 60),
            ((int) round($this->stats['embedding_ms'] / 1000)) % 60,
            $this->stats['chunks'] > 0 ? (int) round($this->stats['embedding_ms'] / $this->stats['chunks']) : 0,
            $this->stats['fresh'],
        ));

        return self::SUCCESS;
    }
}
