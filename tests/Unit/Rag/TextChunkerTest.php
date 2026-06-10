<?php

declare(strict_types=1);

namespace Tests\Unit\Rag;

use App\Rag\Chunking\TextChunker;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextChunkerTest extends TestCase
{
    #[Test]
    public function short_text_yields_a_single_chunk(): void
    {
        $chunker = new TextChunker(maxLength: 100, overlap: 20);

        $chunks = $chunker->chunk('A short paragraph.');

        $this->assertSame(['A short paragraph.'], $chunks);
    }

    #[Test]
    public function empty_text_yields_no_chunk(): void
    {
        $chunker = new TextChunker();

        $this->assertSame([], $chunker->chunk("  \n\n  "));
    }

    #[Test]
    public function paragraphs_are_packed_together_until_the_limit(): void
    {
        $chunker = new TextChunker(maxLength: 50, overlap: 10);

        $chunks = $chunker->chunk("First paragraph.\n\nSecond one.\n\nThird paragraph here.");

        $this->assertSame("First paragraph.\n\nSecond one.", $chunks[0]);
        $this->assertCount(2, $chunks);
    }

    #[Test]
    public function no_chunk_exceeds_the_maximum_length(): void
    {
        $chunker = new TextChunker(maxLength: 80, overlap: 15);
        $text = implode("\n\n", array_fill(0, 20, 'A sentence of reasonable length, repeated.'));

        foreach ($chunker->chunk($text) as $chunk) {
            $this->assertLessThanOrEqual(80, mb_strlen($chunk));
        }
    }

    #[Test]
    public function consecutive_chunks_share_an_overlap(): void
    {
        $chunker = new TextChunker(maxLength: 40, overlap: 12);

        $chunks = $chunker->chunk("alpha beta gamma delta epsilon.\n\nzeta eta theta iota kappa.");

        $this->assertCount(2, $chunks);
        // The second chunk starts with the tail of the first one.
        $tail = mb_substr($chunks[1], 0, mb_strpos($chunks[1], "\n\n") ?: null);
        $this->assertStringContainsString($tail, $chunks[0]);
    }

    #[Test]
    public function an_oversized_paragraph_is_hard_split_at_word_boundaries(): void
    {
        $chunker = new TextChunker(maxLength: 30, overlap: 5);

        $chunks = $chunker->chunk('one two three four five six seven eight nine ten eleven twelve');

        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(30, mb_strlen($chunk));
            $this->assertDoesNotMatchRegularExpression('/\w-$/', $chunk);
        }
    }

    #[Test]
    public function overlap_must_be_smaller_than_max_length(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TextChunker(maxLength: 100, overlap: 100);
    }
}
