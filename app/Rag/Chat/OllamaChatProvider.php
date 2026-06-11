<?php

declare(strict_types=1);

namespace App\Rag\Chat;

use Generator;
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
        // Generation on CPU is slow (~5-15 tokens/s): generous timeout.
        $response = Http::baseUrl($this->baseUrl)
            ->timeout(600)
            ->post('/api/chat', $this->payload($system, $user, stream: false))
            ->throw();

        /** @var array{message: array{content: string}} $data */
        $data = $response->json();

        return trim($data['message']['content']);
    }

    public function chatStream(string $system, string $user): Generator
    {
        // With stream=true, Ollama answers as NDJSON: one JSON object per
        // line, each carrying a fragment of the message being generated.
        $body = Http::baseUrl($this->baseUrl)
            ->timeout(600)
            ->withOptions(['stream' => true])
            ->post('/api/chat', $this->payload($system, $user, stream: true))
            ->throw()
            ->toPsrResponse()
            ->getBody();

        $buffer = '';

        while (! $body->eof()) {
            $buffer .= $body->read(1024);

            while (($newline = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newline);
                $buffer = substr($buffer, $newline + 1);

                $delta = $this->deltaFrom($line);

                if ($delta !== '') {
                    yield $delta;
                }
            }
        }

        $delta = $this->deltaFrom($buffer);

        if ($delta !== '') {
            yield $delta;
        }
    }

    public function describe(): string
    {
        return sprintf('ollama (%s)', $this->model);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $system, string $user, bool $stream): array
    {
        $payload = [
            'model' => $this->model,
            'stream' => $stream,
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

        return $payload;
    }

    private function deltaFrom(string $line): string
    {
        if (trim($line) === '') {
            return '';
        }

        /** @var array{message?: array{content?: string}}|null $data */
        $data = json_decode($line, true);

        return $data['message']['content'] ?? '';
    }
}
