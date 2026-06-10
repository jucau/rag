<?php

declare(strict_types=1);

namespace App\Rag\Chunking;

/**
 * Contextual, section-aware chunking (ADR-0013).
 *
 * Wikipedia plain-text extracts keep their section headings as
 * "== Heading ==" lines. Splitting on them and prefixing every chunk
 * with "[Article : X — Section : Y]" keeps each passage attached to the
 * entity it talks about — the quality audit showed that anonymous
 * chunks were the main cause of cross-entity confusion (a derby chunk
 * mentioning Geoffroy-Guichard answering a question about OL's home).
 */
final readonly class SectionAwareChunker
{
    public function __construct(private TextChunker $chunker)
    {
    }

    /**
     * @return list<string>
     */
    public function chunk(string $articleTitle, string $text): array
    {
        $chunks = [];

        foreach ($this->splitIntoSections($text) as [$section, $content]) {
            $header = $section === null
                ? sprintf('[Article : %s]', $articleTitle)
                : sprintf('[Article : %s — Section : %s]', $articleTitle, $section);

            foreach ($this->chunker->chunk($content) as $passage) {
                $chunks[] = $header . "\n" . $passage;
            }
        }

        return $chunks;
    }

    /**
     * Splits the text on "== Heading ==" lines. The first element (before
     * any heading) is the article introduction, with a null section.
     *
     * @return list<array{string|null, string}>
     */
    private function splitIntoSections(string $text): array
    {
        $parts = preg_split(
            '/^\s*={2,6}\s*(.+?)\s*={2,6}\s*$/m',
            $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE,
        );

        if ($parts === false) {
            return [[null, $text]];
        }

        $sections = [];
        $intro = trim($parts[0]);

        if ($intro !== '') {
            $sections[] = [null, $intro];
        }

        $counter = count($parts);

        // After the intro, $parts alternates: heading, content, heading, …
        for ($i = 1; $i < $counter; $i += 2) {
            $heading = trim($parts[$i]);
            $content = trim($parts[$i + 1] ?? '');

            // Headings followed by no text (e.g. sections whose tables were
            // stripped by the extracts API) produce nothing to embed.
            if ($content !== '') {
                $sections[] = [$heading, $content];
            }
        }

        return $sections;
    }
}
