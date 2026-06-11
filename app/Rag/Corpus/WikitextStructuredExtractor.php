<?php

declare(strict_types=1);

namespace App\Rag\Corpus;

/**
 * Extracts the structured data that prop=extracts strips (ADR-0015):
 * the infobox and the wikitables, linearised into plain-text sections.
 *
 * The output sections use "== Heading ==" markers so the
 * SectionAwareChunker picks them up like any prose section — each
 * standings row or infobox field becomes searchable text such as
 * "Entraîneur : Paulo Fonseca".
 *
 * The parser is deliberately pragmatic: Wikipedia templates are a
 * full-blown language; we clean the common constructs (links, refs,
 * simple templates) and skip the rest.
 */
final readonly class WikitextStructuredExtractor
{
    private const array INFOBOX_SKIPPED_KEYS = [
        'image', 'logo', 'carte', 'blason', 'drapeau', 'upright', 'taille image',
        'légende', 'alt', 'site web', 'pattern', 'couleur', 'personnage',
    ];

    /**
     * Returns "== Heading ==\ncontent" blocks, ready to be appended to
     * the article's plain-text extract.
     *
     * @return list<string>
     */
    public function extract(string $wikitext): array
    {
        $sections = [];

        $infobox = $this->extractInfobox($wikitext);

        if ($infobox !== null) {
            $sections[] = "== Fiche du club (infobox) ==\n" . $infobox;
        }

        foreach ($this->extractTables($wikitext) as $index => [$caption, $content]) {
            $heading = $caption === '' ? sprintf('Tableau %d', $index + 1) : $caption;
            $sections[] = sprintf("== Tableau : %s ==\n%s", $heading, $content);
        }

        return $sections;
    }

    /**
     * Linearises the first {{Infobox …}} template into "key : value" lines.
     */
    private function extractInfobox(string $wikitext): ?string
    {
        $start = stripos($wikitext, '{{Infobox');

        if ($start === false) {
            return null;
        }

        $body = $this->balancedBlock(substr($wikitext, $start), '{{', '}}');

        if ($body === null) {
            return null;
        }

        // Drop the surrounding braces and the template name line.
        $inner = substr($body, 2, -2);
        $params = $this->splitTopLevel($inner, '|');
        array_shift($params); // the "Infobox Club de football" name

        $lines = [];

        foreach ($params as $param) {
            if (! str_contains($param, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $param, 2);
            $key = trim(str_replace('_', ' ', trim($key)));
            $value = $this->clean($value);
            if ($key === '') {
                continue;
            }

            if ($value === '') {
                continue;
            }

            if ($this->isSkippedKey($key)) {
                continue;
            }

            $lines[] = sprintf('%s : %s.', ucfirst($key), $value);
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * Linearises every top-level wikitable ({| … |}) into one line per row.
     *
     * @return list<array{string, string}> [caption, linearised rows]
     */
    private function extractTables(string $wikitext): array
    {
        $tables = [];
        $offset = 0;

        while (($start = strpos($wikitext, "{|", $offset)) !== false) {
            $block = $this->balancedBlock(substr($wikitext, $start), '{|', '|}');

            if ($block === null) {
                break;
            }

            $offset = $start + strlen($block);
            $parsed = $this->parseTable($block);

            if ($parsed !== null) {
                $tables[] = $parsed;
            }
        }

        return $tables;
    }

    /**
     * @return array{string, string}|null
     */
    private function parseTable(string $block): ?array
    {
        $caption = '';
        $headers = [];
        $rows = [];
        $currentRow = [];

        foreach (explode("\n", $block) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '{|')) {
                continue;
            }

            if ($line === '|}') {
                continue;
            }

            if (str_starts_with($line, '|+')) {
                $caption = $this->clean(substr($line, 2));

                continue;
            }

            if (str_starts_with($line, '|-')) {
                if ($currentRow !== []) {
                    $rows[] = $currentRow;
                    $currentRow = [];
                }

                continue;
            }

            if (str_starts_with($line, '!')) {
                foreach (preg_split('/!!/', substr($line, 1)) ?: [] as $cell) {
                    $headers[] = $this->clean($this->stripCellAttributes($cell));
                }

                continue;
            }

            if (str_starts_with($line, '|')) {
                foreach (preg_split('/\|\|/', substr($line, 1)) ?: [] as $cell) {
                    $currentRow[] = $this->clean($this->stripCellAttributes($cell));
                }
            }
        }

        if ($currentRow !== []) {
            $rows[] = $currentRow;
        }

        if ($rows === []) {
            return null;
        }

        $lines = array_map(
            fn (array $row): string => $this->lineariseRow($headers, $row),
            $rows,
        );

        return [$caption, implode("\n", array_filter($lines, fn (string $l): bool => $l !== ''))];
    }

    /**
     * @param list<string> $headers
     * @param list<string> $row
     */
    private function lineariseRow(array $headers, array $row): string
    {
        $cells = [];

        foreach ($row as $index => $value) {
            if ($value === '') {
                continue;
            }

            $header = $headers[$index] ?? null;
            $cells[] = $header === null || $header === '' ? $value : sprintf('%s : %s', $header, $value);
        }

        return implode(' — ', $cells);
    }

    /**
     * "style="…" | value" → "value" (cell attributes end at the first
     * single pipe inside a cell).
     */
    private function stripCellAttributes(string $cell): string
    {
        $position = strpos($cell, '|');

        if ($position !== false && ! str_contains(substr($cell, 0, $position), '[[')) {
            return substr($cell, $position + 1);
        }

        return $cell;
    }

    /**
     * Returns the block starting at offset 0 with balanced delimiters,
     * e.g. "{{…{{…}}…}}", or null when unbalanced.
     */
    private function balancedBlock(string $text, string $open, string $close): ?string
    {
        $depth = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length - 1; ++$i) {
            $pair = $text[$i] . $text[$i + 1];

            if ($pair === $open) {
                ++$depth;
                ++$i;
            } elseif ($pair === $close) {
                --$depth;
                ++$i;

                if ($depth === 0) {
                    return substr($text, 0, $i + 1);
                }
            }
        }

        return null;
    }

    /**
     * Splits on a delimiter, ignoring those nested in {{…}} or [[…]].
     *
     * @return list<string>
     */
    private function splitTopLevel(string $text, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $braces = 0;
        $brackets = 0;
        $length = strlen($text);

        for ($i = 0; $i < $length; ++$i) {
            $char = $text[$i];
            $pair = $char . ($text[$i + 1] ?? '');

            if ($pair === '{{') {
                ++$braces;
            } elseif ($pair === '}}') {
                --$braces;
            } elseif ($pair === '[[') {
                ++$brackets;
            } elseif ($pair === ']]') {
                --$brackets;
            }

            if ($char === $delimiter && $braces === 0 && $brackets === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * Cleans the common wikitext constructs out of a value.
     */
    private function clean(string $value): string
    {
        // Comments and references never carry display text.
        $value = (string) preg_replace('/<!--.*?-->/s', '', $value);
        $value = (string) preg_replace('/<ref[^>]*\/>/i', '', $value);
        $value = (string) preg_replace('/<ref[^>]*>.*?<\/ref>/is', '', $value);

        // Files and images.
        $value = (string) preg_replace('/\[\[(?:Fichier|File|Image)\s*:[^\]]*\]\]/iu', '', $value);

        // Links: [[target|label]] → label, [[target]] → target.
        $value = (string) preg_replace('/\[\[[^\]|]*\|([^\]]*)\]\]/u', '$1', $value);
        $value = (string) preg_replace('/\[\[([^\]]*)\]\]/u', '$1', $value);

        // Simple templates: {{tpl|a|b}} → "a b" (named args dropped),
        // {{formatnum:123}} → "123". Applied twice for one nesting level.
        for ($pass = 0; $pass < 2; ++$pass) {
            $value = (string) preg_replace_callback(
                '/\{\{([^{}]*)\}\}/u',
                function (array $matches): string {
                    $parts = explode('|', $matches[1]);
                    $name = array_shift($parts);
                    $args = array_values(array_filter(
                        array_map(trim(...), $parts),
                        fn (string $part): bool => $part !== '' && ! str_contains($part, '='),
                    ));

                    if ($args !== []) {
                        return implode(' ', $args);
                    }

                    return str_contains($name, ':') ? trim(explode(':', $name, 2)[1]) : '';
                },
                $value,
            );
        }

        // Formatting leftovers.
        $value = str_replace(["'''", "''"], '', $value);
        $value = (string) preg_replace('/<br\s*\/?\s*>/i', ', ', $value);
        $value = strip_tags($value);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim($value, " \t\n\r\0\x0B,;");
    }

    private function isSkippedKey(string $key): bool
    {
        $normalized = mb_strtolower($key);
        return array_any(self::INFOBOX_SKIPPED_KEYS, fn ($skipped): bool => str_contains($normalized, (string) $skipped));
    }
}
