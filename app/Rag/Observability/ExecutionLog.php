<?php

declare(strict_types=1);

namespace App\Rag\Observability;

use App\Rag\Indexing\DocumentIndexer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\File;

/**
 * Structured execution log for batch commands (ADR-0021): one JSON file
 * per run in storage/rag-runs/, with run-level metadata, per-item
 * events and a final summary. Written even when the run crashes — the
 * 429 incidents of June 2026 left no exploitable trace.
 */
final class ExecutionLog
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $events = [];

    private readonly Carbon $startedAt;

    private readonly int $startedNs;

    private readonly string $basePath;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly string $command,
        private readonly array $options = [],
    ) {
        $this->startedAt = Date::now();
        $this->startedNs = (int) hrtime(true);

        $directory = storage_path('rag-runs');
        File::ensureDirectoryExists($directory);

        $this->basePath = sprintf(
            '%s/%s_%s',
            $directory,
            $this->startedAt->format('Y-m-d_His'),
            str_replace(':', '-', $this->command),
        );
    }

    /**
     * The live event stream: one JSON line per event, appended as it
     * happens — `tail -f` this file to watch a run in real time.
     */
    public function livePath(): string
    {
        return $this->basePath . '.jsonl';
    }

    /**
     * Records one per-item event (a page fetched, a document indexed,
     * a benchmark run…).
     *
     * @param array<string, mixed> $data
     */
    public function event(string $type, array $data = []): void
    {
        $event = [
            't' => Date::now()->toIso8601String(),
            'type' => $type,
            ...$data,
        ];

        $this->events[] = $event;

        // Streamed immediately (unlike the consolidated file written at
        // finish()): a crashed or running run is observable live.
        File::append(
            $this->livePath(),
            json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        );
    }

    /**
     * Writes the JSON file and returns its path. Call from a `finally`
     * block so crashes leave a trace too.
     *
     * @param array<string, mixed> $summary
     */
    public function finish(string $status, array $summary = [], ?string $error = null): string
    {
        $payload = [
            'command' => $this->command,
            'options' => $this->options,
            'chunking_version' => DocumentIndexer::VERSION,
            'started_at' => $this->startedAt->toIso8601String(),
            'finished_at' => Date::now()->toIso8601String(),
            'duration_ms' => (int) round(((int) hrtime(true) - $this->startedNs) / 1_000_000),
            'status' => $status,
            'error' => $error,
            'summary' => $summary,
            'events' => $this->events,
        ];

        $path = $this->basePath . '.json';

        File::put($path, (string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return $path;
    }
}
