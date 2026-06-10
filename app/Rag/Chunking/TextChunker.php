<?php

declare(strict_types=1);

namespace App\Rag\Chunking;

use InvalidArgumentException;

/**
 * Paragraph-aware chunking with overlap (ADR-0009).
 *
 * Strategy: pack whole paragraphs into chunks up to $maxLength characters.
 * Paragraphs are the natural semantic unit of prose — cutting mid-sentence
 * produces chunks whose embedding represents half an idea. When a chunk is
 * full, the next one starts with the tail of the previous one ($overlap
 * characters, cut at a word boundary) so that an idea sitting astride two
 * chunks is fully present in at least one of them.
 */
final readonly class TextChunker
{
    public function __construct(
        private int $maxLength = 1200,
        private int $overlap = 200,
    ) {
        if ($this->overlap >= $this->maxLength) {
            throw new InvalidArgumentException('Overlap must be smaller than the max chunk length.');
        }
    }

    /**
     * @return list<string>
     */
    public function chunk(string $text): array
    {
        $paragraphs = $this->splitIntoParagraphs($text);

        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            // A single paragraph longer than a whole chunk gets hard-split
            // at word boundaries first.
            foreach ($this->splitOversized($paragraph) as $piece) {
                if ($current === '') {
                    $current = $piece;

                    continue;
                }

                if (mb_strlen($current . "\n\n" . $piece) <= $this->maxLength) {
                    $current .= "\n\n" . $piece;

                    continue;
                }

                // Current chunk is full: emit it, start the next one with
                // its tail so context carries over — unless tail + piece
                // would itself exceed the limit (the invariant "no chunk
                // longer than maxLength" wins over the overlap).
                $chunks[] = $current;
                $tail = $this->tail($current);
                $candidate = $tail === '' ? $piece : $tail . "\n\n" . $piece;
                $current = mb_strlen($candidate) <= $this->maxLength ? $candidate : $piece;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    private function splitIntoParagraphs(string $text): array
    {
        $normalized = str_replace("\r\n", "\n", trim($text));
        $parts = preg_split('/\n{2,}/', $normalized);

        if ($parts === false) {
            return [];
        }

        $paragraphs = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part !== '') {
                $paragraphs[] = $part;
            }
        }

        return $paragraphs;
    }

    /**
     * Hard-split a paragraph that alone exceeds the chunk size, cutting at
     * word boundaries.
     *
     * @return list<string>
     */
    private function splitOversized(string $paragraph): array
    {
        if (mb_strlen($paragraph) <= $this->maxLength) {
            return [$paragraph];
        }

        $words = preg_split('/\s+/', $paragraph);

        if ($words === false) {
            return [$paragraph];
        }

        $pieces = [];
        $current = '';

        foreach ($words as $word) {
            if ($current === '') {
                $current = $word;

                continue;
            }

            if (mb_strlen($current . ' ' . $word) <= $this->maxLength) {
                $current .= ' ' . $word;

                continue;
            }

            $pieces[] = $current;
            $current = $word;
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * Last $overlap characters of a chunk, cut at a word boundary.
     */
    private function tail(string $chunk): string
    {
        if (mb_strlen($chunk) <= $this->overlap) {
            return $chunk;
        }

        $tail = mb_substr($chunk, -$this->overlap);
        $firstSpace = mb_strpos($tail, ' ');

        if ($firstSpace === false) {
            return $tail;
        }

        return trim(mb_substr($tail, $firstSpace + 1));
    }
}
