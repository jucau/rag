<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AskValidationTest extends TestCase
{
    #[Test]
    public function it_rejects_a_model_outside_the_whitelist(): void
    {
        $response = $this->postJson('/ask', [
            'question' => 'Une question ?',
            'provider' => 'ollama',
            'model' => 'mistral:7b',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['model']);
    }

    #[Test]
    public function it_rejects_an_unknown_provider(): void
    {
        $response = $this->postJson('/ask', [
            'question' => 'Une question ?',
            'provider' => 'openai',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['provider']);
    }

    #[Test]
    public function it_rejects_an_empty_question(): void
    {
        $response = $this->postJson('/ask', ['question' => '']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['question']);
    }
}
