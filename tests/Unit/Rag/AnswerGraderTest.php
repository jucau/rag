<?php

declare(strict_types=1);

namespace Tests\Unit\Rag;

use App\Rag\Evaluation\AnswerGrader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnswerGraderTest extends TestCase
{
    private AnswerGrader $grader;

    #[\Override]
    protected function setUp(): void
    {
        $this->grader = new AnswerGrader();
    }

    #[Test]
    public function it_matches_a_key_ignoring_case_accents_and_punctuation(): void
    {
        $this->assertTrue($this->grader->matchesKey(
            "Le club est surnommé « les Verts ».",
            ['les Verts'],
        ));
        $this->assertTrue($this->grader->matchesKey(
            'Son entraîneur est Luis Enrique.',
            ['Luis Enrique'],
        ));
    }

    #[Test]
    public function it_matches_a_number_regardless_of_digit_grouping(): void
    {
        $this->assertTrue($this->grader->matchesKey('La capacité est de 59186 places.', ['59 186']));
        $this->assertTrue($this->grader->matchesKey('Environ 59 186 spectateurs.', ['59186']));
    }

    #[Test]
    public function it_does_not_match_a_key_inside_a_larger_word(): void
    {
        // "vert" must not match inside "couvert".
        $this->assertFalse($this->grader->matchesKey('Le terrain est couvert.', ['vert']));
        // bare "81" must not match inside "1981".
        $this->assertFalse($this->grader->matchesKey('Né en 1981.', ['81 buts']));
    }

    #[Test]
    public function it_detects_a_refusal(): void
    {
        $this->assertTrue($this->grader->isRefusal(
            "Le contexte ne mentionne pas cette information.",
        ));
        $this->assertTrue($this->grader->isRefusal(
            "Je ne peux pas répondre, aucun passage ne le précise.",
        ));
    }

    #[Test]
    public function a_confident_factual_answer_is_not_a_refusal(): void
    {
        $this->assertFalse($this->grader->isRefusal('Le FC Nantes a été fondé en 1943.'));
    }

    #[Test]
    public function correctness_is_independent_of_hedging(): void
    {
        // A hedged answer that still states the fact is correct AND a
        // refusal marker may co-occur — the caller resolves correctness first.
        $answer = "Le contexte ne donne pas la date exacte, mais indique 1943.";
        $this->assertTrue($this->grader->matchesKey($answer, ['1943']));
        $this->assertTrue($this->grader->isRefusal($answer));
    }
}
