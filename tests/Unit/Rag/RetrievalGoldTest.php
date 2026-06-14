<?php

declare(strict_types=1);

namespace Tests\Unit\Rag;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integrity of the retrieval ground truth (benchmark/retrieval-gold.json,
 * ADR-0025): every judged id must point at a real benchmark question, and
 * every question must map to at least one non-empty relevant document
 * title. A typo'd id or an empty list would silently skew recall@k.
 */
final class RetrievalGoldTest extends TestCase
{
    /**
     * @return array<string, array{id: string, category: string, question: string, expected: string}>
     */
    private function questions(): array
    {
        /** @var list<array{id: string, category: string, question: string, expected: string}> $questions */
        $questions = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/benchmark/questions.json'), true);

        $byId = [];
        foreach ($questions as $question) {
            $byId[$question['id']] = $question;
        }

        return $byId;
    }

    /**
     * @return array<string, list<string>>
     */
    private function gold(): array
    {
        /** @var array{judgments: array<string, list<string>>} $decoded */
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/benchmark/retrieval-gold.json'), true);

        return $decoded['judgments'];
    }

    #[Test]
    public function every_judged_id_is_a_real_benchmark_question(): void
    {
        $questions = $this->questions();

        foreach (array_keys($this->gold()) as $id) {
            $this->assertArrayHasKey($id, $questions, sprintf('Gold id "%s" has no matching question.', $id));
        }
    }

    #[Test]
    public function every_judgment_lists_at_least_one_relevant_document(): void
    {
        foreach ($this->gold() as $id => $docs) {
            $this->assertNotEmpty($docs, sprintf('Gold id "%s" has no relevant document.', $id));

            foreach ($docs as $title) {
                $this->assertNotSame('', trim($title), sprintf('Gold id "%s" has an empty title.', $id));
            }
        }
    }

    #[Test]
    public function trap_off_corpus_and_biased_questions_are_intentionally_unjudged(): void
    {
        $gold = $this->gold();

        // E (fabrication traps), F (off-corpus) and G (biased) questions
        // test the guard / generator, not retrieval — they must NOT carry
        // a relevance judgment (see ADR-0025 scope).
        foreach ($this->questions() as $id => $question) {
            if (in_array($question['category'], ['piege-fabrication', 'hors-corpus', 'question-biaisee'], true)) {
                $this->assertArrayNotHasKey($id, $gold, sprintf('Out-of-scope question "%s" should not be judged.', $id));
            }
        }
    }
}
