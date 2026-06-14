<?php

declare(strict_types=1);

namespace App\Rag\Evaluation;

/**
 * Deterministic, judge-free grading of a generated answer (ADR-0026).
 *
 * Two signals, both reproducible and auditable (no LLM judge — the
 * benchmark showed small local models are unreliable judges, ADR-0024):
 *
 *  - matchesKey(): does the answer contain one of the accepted answer
 *    aliases (benchmark/answer-keys.json)? Matching is normalised
 *    (case, accents, punctuation, digit grouping) and token-bounded, so
 *    "vert" does not match "couvert" and "59 186" matches "59186".
 *  - isRefusal(): did the model decline to answer ("le contexte ne
 *    mentionne pas…")? Conditioned on retrieval quality, a refusal on a
 *    question whose answer WAS in the context is an over-refusal — the
 *    generation failure mode this instrument is built to surface.
 *
 * Correctness wins over refusal: an answer that states the right fact
 * while hedging is still correct.
 */
final class AnswerGrader
{
    private const array ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ];

    /**
     * Normalised fragments that signal the model declined to answer.
     * Kept accent-free because normalise() strips accents first.
     */
    private const array REFUSAL_MARKERS = [
        'je ne peux pas', 'je ne dispose', 'je ne trouve', 'je ne sais',
        'aucune information', 'aucun passage', 'aucune mention',
        'ne figure pas', 'ne mentionne pas', 'ne contient pas',
        'ne permet pas', 'n est pas mentionn', 'n est pas precis',
        'n est pas indiqu', 'n est pas disponible', 'non precis',
        'pas d information', 'pas assez d information', 'impossible de',
        'le contexte ne', 'hors du corpus', 'hors corpus',
        'ne fournit pas', 'ne donne pas', 'ne precise pas',
    ];

    /**
     * @param list<string> $keys accepted answer aliases (any present = correct)
     */
    public function matchesKey(string $answer, array $keys): bool
    {
        $haystacks = [$this->normalize($answer)];
        $haystacks[] = $this->compactDigits($haystacks[0]);

        foreach ($keys as $key) {
            $needle = $this->normalize($key);

            if ($needle === '') {
                continue;
            }

            foreach ([$needle, $this->compactDigits($needle)] as $variant) {
                foreach ($haystacks as $haystack) {
                    if ($this->containsToken($haystack, $variant)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function isRefusal(string $answer): bool
    {
        $normalized = $this->normalize($answer);
        return array_any(self::REFUSAL_MARKERS, fn ($marker): bool => str_contains($normalized, (string) $marker));
    }

    /**
     * Lowercase, strip accents, collapse every run of non-alphanumeric
     * characters to a single space, trim. Stable and locale-independent.
     */
    public function normalize(string $text): string
    {
        $lower = mb_strtolower($text);
        $ascii = strtr($lower, self::ACCENTS);
        $spaced = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '';

        return trim($spaced);
    }

    /**
     * Removes spaces between digits so "59 186" and "59186" compare
     * equal, without touching spaces between words.
     */
    private function compactDigits(string $normalized): string
    {
        return preg_replace('/(?<=\d) +(?=\d)/', '', $normalized) ?? $normalized;
    }

    /**
     * True if $needle appears in $haystack bounded by non-alphanumeric
     * edges (so "vert" does not match inside "couvert"). Both arguments
     * are already normalised.
     */
    private function containsToken(string $haystack, string $needle): bool
    {
        return preg_match(
            '/(?<![a-z0-9])' . preg_quote($needle, '/') . '(?![a-z0-9])/',
            $haystack,
        ) === 1;
    }
}
