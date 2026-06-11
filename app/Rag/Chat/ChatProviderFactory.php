<?php

declare(strict_types=1);

namespace App\Rag\Chat;

use Anthropic\Client;
use InvalidArgumentException;

/**
 * Builds a ChatProvider by name, so the configured default can be
 * overridden per call (rag:ask --provider=claude) — the whole point
 * being to compare both providers on the same retrieved context.
 */
final readonly class ChatProviderFactory
{
    public function make(?string $name = null): ChatProvider
    {
        $name ??= config()->string('rag.chat.provider');

        return match ($name) {
            'ollama' => new OllamaChatProvider(
                baseUrl: config()->string('rag.embeddings.ollama.url'),
                model: config()->string('rag.chat.ollama.model'),
                think: $this->thinkOption(),
            ),
            'claude' => new ClaudeChatProvider(
                client: new Client(apiKey: config()->string('rag.chat.claude.api_key')),
                model: config()->string('rag.chat.claude.model'),
            ),
            default => throw new InvalidArgumentException(sprintf(
                'Unknown chat provider "%s" (expected "ollama" or "claude").',
                $name,
            )),
        };
    }

    private function thinkOption(): ?bool
    {
        $think = config('rag.chat.ollama.think');

        return is_bool($think) ? $think : null;
    }
}
