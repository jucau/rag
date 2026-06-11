<?php

declare(strict_types=1);

namespace App\Rag\Chat;

/**
 * The local models a client may request — the enum IS the whitelist:
 * anything outside these cases is rejected at validation time, so the
 * browser can never ask Ollama for an arbitrary model.
 *
 * Benchmark results behind each case: docs/04-benchmark-local-models.md
 * (ADR-0019).
 */
enum OllamaModel: string
{
    case Gemma3_4B = 'gemma3:4b';
    case Gemma3_1B = 'gemma3:1b';
    case Qwen3_1_7B = 'qwen3:1.7b';
    case Llama32_3B = 'llama3.2:3b';

    public function label(): string
    {
        return match ($this) {
            self::Gemma3_4B => 'Gemma 3 4B — local, le plus complet',
            self::Gemma3_1B => 'Gemma 3 1B — local, le plus rapide',
            self::Qwen3_1_7B => 'Qwen 3 1.7B — local',
            self::Llama32_3B => 'Llama 3.2 3B — local (défaut, le plus fiable)',
        };
    }

    /**
     * Thinking models reason before answering by default — far too slow
     * on CPU. The enum carries that knowledge so callers can't forget it.
     */
    public function think(): ?bool
    {
        return match ($this) {
            self::Qwen3_1_7B => false,
            default => null,
        };
    }
}
