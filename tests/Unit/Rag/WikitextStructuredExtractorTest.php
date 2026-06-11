<?php

declare(strict_types=1);

namespace Tests\Unit\Rag;

use App\Rag\Corpus\WikitextStructuredExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WikitextStructuredExtractorTest extends TestCase
{
    private WikitextStructuredExtractor $extractor;

    #[\Override]
    protected function setUp(): void
    {
        $this->extractor = new WikitextStructuredExtractor();
    }

    #[Test]
    public function it_linearises_an_infobox_into_key_value_lines(): void
    {
        $wikitext = <<<'WIKI'
            {{Infobox Club de football
            | nom = Olympique lyonnais
            | logo = OL logo.svg
            | entraîneur = [[Paulo Fonseca]]
            | stade = [[Parc Olympique lyonnais|Groupama Stadium]]
            | capacité = {{formatnum:59186}}<ref>Site officiel</ref>
            }}
            Texte de l'article.
            WIKI;

        $sections = $this->extractor->extract($wikitext);

        $this->assertCount(1, $sections);
        $this->assertStringStartsWith("== Fiche du club (infobox) ==\n", $sections[0]);
        $this->assertStringContainsString('Nom : Olympique lyonnais.', $sections[0]);
        $this->assertStringContainsString('Entraîneur : Paulo Fonseca.', $sections[0]);
        $this->assertStringContainsString('Stade : Groupama Stadium.', $sections[0]);
        $this->assertStringContainsString('Capacité : 59186.', $sections[0]);
        $this->assertStringNotContainsString('logo', $sections[0]);
        $this->assertStringNotContainsString('<ref>', $sections[0]);
    }

    #[Test]
    public function it_linearises_a_wikitable_row_by_row_with_headers(): void
    {
        $wikitext = <<<'WIKI'
            {| class="wikitable"
            |+ Classement
            ! Rang !! Club !! Points
            |-
            | 1 || [[Paris Saint-Germain Football Club|Paris SG]] || 45
            |-
            | 2 || [[Olympique lyonnais]] || 42
            |}
            WIKI;

        $sections = $this->extractor->extract($wikitext);

        $this->assertCount(1, $sections);
        $this->assertStringStartsWith("== Tableau : Classement ==\n", $sections[0]);
        $this->assertStringContainsString('Rang : 1 — Club : Paris SG — Points : 45', $sections[0]);
        $this->assertStringContainsString('Rang : 2 — Club : Olympique lyonnais — Points : 42', $sections[0]);
    }

    #[Test]
    public function tables_without_caption_get_a_numbered_heading(): void
    {
        $wikitext = "{| class=\"wikitable\"\n! A !! B\n|-\n| 1 || 2\n|}";

        $sections = $this->extractor->extract($wikitext);

        $this->assertStringStartsWith("== Tableau : Tableau 1 ==\n", $sections[0]);
    }

    #[Test]
    public function cell_attributes_are_stripped(): void
    {
        $wikitext = "{| class=\"wikitable\"\n! Club\n|-\n| style=\"background:#eee\" | Le Havre AC\n|}";

        $sections = $this->extractor->extract($wikitext);

        $this->assertStringContainsString('Club : Le Havre AC', $sections[0]);
        $this->assertStringNotContainsString('background', $sections[0]);
    }

    #[Test]
    public function it_returns_nothing_when_there_is_no_structured_data(): void
    {
        $this->assertSame([], $this->extractor->extract('Du texte sans structure.'));
    }

    #[Test]
    public function nested_templates_inside_the_infobox_do_not_break_parsing(): void
    {
        $wikitext = <<<'WIKI'
            {{Infobox Club de football
            | nom = OL
            | président = {{drapeau|France}} [[Michele Kang]]
            }}
            WIKI;

        $sections = $this->extractor->extract($wikitext);

        $this->assertStringContainsString('Président : France Michele Kang.', $sections[0]);
    }
}
