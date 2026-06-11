<?php

declare(strict_types=1);

namespace App\Rag\Chat;

use Illuminate\Support\Facades\Http;

final readonly class OllamaChatProvider implements ChatProvider
{
    public function __construct(
        private string $baseUrl,
        private string $model,
        private ?bool $think = null,
    ) {
    }

    public function chat(string $system, string $user): string
    {
        $payload = [
            'model' => $this->model,
            'stream' => false,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        // Thinking models (qwen3…) reason before answering by default —
        // accuracy/latency trade-off. Only sent when configured: models
        // without thinking support reject the parameter.
        if ($this->think !== null) {
            $payload['think'] = $this->think;
        }

        // Generation on CPU is slow (~5-15 tokens/s): generous timeout.
        $response = Http::baseUrl($this->baseUrl)
            ->timeout(600)
            ->post('/api/chat', $payload)
            ->throw();

        /** @var array{message: array{content: string}} $data */
        $data = $response->json();

        return trim($data['message']['content']);
    }

    public function describe(): string
    {
        return sprintf('ollama (%s)', $this->model);
    }
}
