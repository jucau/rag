<?php

declare(strict_types=1);

namespace Tests\Unit\Rag;

use App\Rag\Observability\ExecutionLog;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ExecutionLogTest extends TestCase
{
    #[Test]
    public function it_writes_one_json_file_per_run_with_events_and_summary(): void
    {
        $log = new ExecutionLog('rag:fetch-wikipedia', ['all' => true]);
        $log->event('refreshed', ['title' => 'Olympique lyonnais', 'revision_id' => 236733391]);
        $log->event('unchanged', ['title' => 'Stade de Gerland']);

        $path = $log->finish('success', ['refreshed' => 1, 'unchanged' => 1]);
        File::delete($log->livePath());

        $this->assertFileExists($path);
        $this->assertStringContainsString('rag-runs/', $path);

        /** @var array{command: string, status: string, error: string|null, summary: array<string, int>, events: list<array<string, mixed>>, chunking_version: string} $data */
        $data = json_decode((string) file_get_contents($path), true);

        $this->assertSame('rag:fetch-wikipedia', $data['command']);
        $this->assertSame('success', $data['status']);
        $this->assertNull($data['error']);
        $this->assertSame(['refreshed' => 1, 'unchanged' => 1], $data['summary']);
        $this->assertCount(2, $data['events']);
        $this->assertSame('refreshed', $data['events'][0]['type']);
        $this->assertSame('Olympique lyonnais', $data['events'][0]['title']);
        $this->assertNotEmpty($data['chunking_version']);

        File::delete($path);
    }

    #[Test]
    public function events_stream_live_to_a_jsonl_file_before_the_run_ends(): void
    {
        $log = new ExecutionLog('rag:benchmark');
        $log->event('run', ['model' => 'llama3.2:3b', 'question_id' => 'A01', 'answer' => 'réponse']);

        // Before finish(): the live file already carries the event.
        $this->assertFileExists($log->livePath());

        $lines = array_filter(explode(PHP_EOL, (string) file_get_contents($log->livePath())));
        $this->assertCount(1, $lines);

        /** @var array{type: string, model: string} $event */
        $event = json_decode((string) array_first($lines), true);
        $this->assertSame('run', $event['type']);
        $this->assertSame('llama3.2:3b', $event['model']);

        File::delete($log->livePath());
        File::delete($log->finish('success'));
    }

    #[Test]
    public function a_failed_run_records_the_error_and_the_events_so_far(): void
    {
        $log = new ExecutionLog('rag:index');
        $log->event('indexed', ['title' => 'LOSC Lille', 'chunks' => 113]);

        $path = $log->finish('failure', ['indexed' => 1], error: 'HTTP 429 from Wikimedia');
        File::delete($log->livePath());

        /** @var array{status: string, error: string|null, events: list<array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents($path), true);

        $this->assertSame('failure', $data['status']);
        $this->assertSame('HTTP 429 from Wikimedia', $data['error']);
        $this->assertCount(1, $data['events']);

        File::delete($path);
    }
}
