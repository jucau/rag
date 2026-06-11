<?php

declare(strict_types=1);

namespace App\Rag\Chat;

use Anthropic\Client;

/**
 * Builds a ChatProvider, so the configured defaults can be overridden
 * per call (rag:ask --provider/--model, the chat UI selector) — the
 * whole point being to compare providers and models on the same
 * retrieved context.
 */
final readonly class ChatProviderFactory
{
    public function make(?ChatProviderName $provider = null, ?OllamaModel $model = null): ChatProvider
    {
        $provider ??= ChatProviderName::from(config()->string('rag.chat.provider'));

        return match ($provider) {
            ChatProviderName::Ollama => $this->ollama($model),
            ChatProviderName::Claude => new ClaudeChatProvider(
                client: new Client(apiKey: config()->string('rag.chat.claude.api_key')),
                model: config()->string('rag.chat.claude.model'),
            ),
        };
    }

    private function ollama(?OllamaModel $model): OllamaChatProvider
    {
        $model ??= OllamaModel::tryFrom(config()->string('rag.chat.ollama.model'))
            ?? OllamaModel::Llama32_3B;

        return new OllamaChatProvider(
            baseUrl: config()->string('rag.embeddings.ollama.url'),
            model: $model->value,
            // The enum knows its model (qwen3 must not think on CPU);
            // the env option remains an explicit override.
            think: $this->thinkOption() ?? $model->think(),
        );
    }

    private function thinkOption(): ?bool
    {
        $think = config('rag.chat.ollama.think');

        return is_bool($think) ? $think : null;
    }
}
