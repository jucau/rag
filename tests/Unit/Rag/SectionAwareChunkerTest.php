<?php

declare(strict_types=1);

namespace Tests\Unit\Rag;

use App\Rag\Chunking\SectionAwareChunker;
use App\Rag\Chunking\TextChunker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SectionAwareChunkerTest extends TestCase
{
    private SectionAwareChunker $chunker;

    #[\Override]
    protected function setUp(): void
    {
        $this->chunker = new SectionAwareChunker(new TextChunker(maxLength: 200, overlap: 40));
    }

    #[Test]
    public function the_introduction_is_prefixed_with_the_article_only(): void
    {
        $chunks = $this->chunker->chunk('Olympique lyonnais', 'Le club est fondé en 1950.');

        $this->assertSame(
            ["[Article : Olympique lyonnais]\nLe club est fondé en 1950."],
            $chunks,
        );
    }

    #[Test]
    public function sections_are_split_and_prefixed_with_their_heading(): void
    {
        $text = "Introduction du club.\n\n== Histoire ==\nFondé en 1950.\n\n== Stade ==\nLe club joue au Parc OL.";

        $chunks = $this->chunker->chunk('Olympique lyonnais', $text);

        $this->assertCount(3, $chunks);
        $this->assertStringStartsWith("[Article : Olympique lyonnais]\n", $chunks[0]);
        $this->assertStringStartsWith("[Article : Olympique lyonnais — Section : Histoire]\n", $chunks[1]);
        $this->assertStringStartsWith("[Article : Olympique lyonnais — Section : Stade]\n", $chunks[2]);
    }

    #[Test]
    public function boilerplate_end_matter_sections_are_dropped_but_the_intro_is_kept(): void
    {
        $text = "Hugo Lloris est un gardien français.\n\n"
            . "== Carrière ==\nFormé à Nice, il rejoint l'OL en 2008.\n\n"
            . "== Liens externes ==\nSite officiel\n\n"
            . "== Bibliographie sur le club ==\nUn livre\n\n"
            . "== Notes et références ==\nCet article est issu de…\n\n"
            . "== Articles connexes ==\nListe des joueurs de l'OL";

        $chunks = $this->chunker->chunk('Hugo Lloris', $text);

        // Only the intro and the real "Carrière" section survive.
        $this->assertCount(2, $chunks);
        $this->assertStringStartsWith("[Article : Hugo Lloris]\n", $chunks[0]);
        $this->assertStringStartsWith("[Article : Hugo Lloris — Section : Carrière]\n", $chunks[1]);

        $joined = implode("\n", $chunks);
        $this->assertStringNotContainsString('Liens externes', $joined);
        $this->assertStringNotContainsString('Bibliographie', $joined);
        $this->assertStringNotContainsString('Notes et références', $joined);
        $this->assertStringNotContainsString('Articles connexes', $joined);
    }

    #[Test]
    public function sub_headings_become_the_current_section(): void
    {
        $text = "== Histoire ==\nLes débuts.\n\n=== Années 2000 ===\nLa décennie dorée.";

        $chunks = $this->chunker->chunk('OL', $text);

        $this->assertStringStartsWith("[Article : OL — Section : Histoire]\n", $chunks[0]);
        $this->assertStringStartsWith("[Article : OL — Section : Années 2000]\n", $chunks[1]);
    }

    #[Test]
    public function empty_sections_are_skipped(): void
    {
        $text = "== Classement ==\n\n== Stade ==\nLe Parc OL.";

        $chunks = $this->chunker->chunk('OL', $text);

        $this->assertCount(1, $chunks);
        $this->assertStringContainsString('Section : Stade', $chunks[0]);
    }

    #[Test]
    public function long_sections_are_chunked_with_the_same_header(): void
    {
        $sentence = 'Une phrase qui raconte un épisode de la saison du club lyonnais. ';
        $text = "== Histoire ==\n" . str_repeat($sentence, 20);

        $chunks = $this->chunker->chunk('OL', $text);

        $this->assertGreaterThan(1, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertStringStartsWith("[Article : OL — Section : Histoire]\n", $chunk);
        }
    }
}
