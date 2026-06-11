<?php

declare(strict_types=1);

namespace Tests\Unit\Rag;

use App\Models\Document;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DocumentIndexingReasonTest extends TestCase
{
    private const string VERSION = '2026-06-11.1';

    #[Test]
    public function a_never_indexed_document_is_stale(): void
    {
        $document = $this->document(['chunked_at' => null]);

        $this->assertSame('never indexed', $document->indexingReason(self::VERSION));
    }

    #[Test]
    public function a_document_whose_content_changed_is_stale(): void
    {
        $document = $this->document([
            'content_hash' => 'hash-after-refetch',
            'chunked_content_hash' => 'hash-at-indexing-time',
        ]);

        $this->assertSame(
            'content changed since last indexing',
            $document->indexingReason(self::VERSION),
        );
    }

    #[Test]
    public function a_document_indexed_with_an_older_strategy_is_stale(): void
    {
        $document = $this->document(['chunking_version' => '2026-06-10.1']);

        $this->assertSame(
            'chunking strategy changed (2026-06-10.1 → ' . self::VERSION . ')',
            $document->indexingReason(self::VERSION),
        );
    }

    #[Test]
    public function an_up_to_date_document_is_fresh(): void
    {
        $document = $this->document([]);

        $this->assertNull($document->indexingReason(self::VERSION));
    }

    #[Test]
    public function a_refetch_that_changes_nothing_does_not_make_it_stale(): void
    {
        // fetched_at moved, but the hash is identical: no re-embedding.
        $document = $this->document(['fetched_at' => '2026-06-12 09:00:00']);

        $this->assertNull($document->indexingReason(self::VERSION));
    }

    /**
     * An up-to-date document, overridable per test.
     *
     * @param array<string, mixed> $overrides
     */
    private function document(array $overrides): Document
    {
        $document = new Document();
        $document->forceFill(array_merge([
            'content_hash' => 'same-hash',
            'chunked_content_hash' => 'same-hash',
            'chunked_at' => '2026-06-11 12:00:00',
            'chunking_version' => self::VERSION,
            'fetched_at' => '2026-06-11 10:00:00',
        ], $overrides));

        return $document;
    }
}
