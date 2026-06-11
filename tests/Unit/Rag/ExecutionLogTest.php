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
    public function a_failed_run_records_the_error_and_the_events_so_far(): void
    {
        $log = new ExecutionLog('rag:index');
        $log->event('indexed', ['title' => 'LOSC Lille', 'chunks' => 113]);

        $path = $log->finish('failure', ['indexed' => 1], error: 'HTTP 429 from Wikimedia');

        /** @var array{status: string, error: string|null, events: list<array<string, mixed>>} $data */
        $data = json_decode((string) file_get_contents($path), true);

        $this->assertSame('failure', $data['status']);
        $this->assertSame('HTTP 429 from Wikimedia', $data['error']);
        $this->assertCount(1, $data['events']);

        File::delete($path);
    }
}
