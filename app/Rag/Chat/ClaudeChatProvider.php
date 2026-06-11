<?php

declare(strict_types=1);

namespace App\Rag\Chat;

use Anthropic\Client;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\TextDelta;
use Generator;

final readonly class ClaudeChatProvider implements ChatProvider
{
    public function __construct(
        private Client $client,
        private string $model,
    ) {
    }

    public function chat(string $system, string $user): string
    {
        $message = $this->client->messages->create(
            maxTokens: 16000,
            messages: [
                ['role' => 'user', 'content' => $user],
            ],
            model: $this->model,
            system: $system,
            // Adaptive thinking: Claude decides whether the question needs
            // deeper reasoning before answering.
            thinking: ['type' => 'adaptive'],
        );

        // Content is polymorphic (thinking blocks may precede the text):
        // concatenate text blocks only.
        $answer = '';

        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                $answer .= $block->text;
            }
        }

        return trim($answer);
    }

    public function chatStream(string $system, string $user): Generator
    {
        $stream = $this->client->messages->createStream(
            maxTokens: 16000,
            messages: [
                ['role' => 'user', 'content' => $user],
            ],
            model: $this->model,
            system: $system,
            thinking: ['type' => 'adaptive'],
        );

        // Thinking deltas may precede text deltas: only text reaches
        // the caller.
        foreach ($stream as $event) {
            if ($event instanceof RawContentBlockDeltaEvent && $event->delta instanceof TextDelta) {
                yield $event->delta->text;
            }
        }
    }

    public function describe(): string
    {
        return sprintf('claude (%s)', $this->model);
    }
}
