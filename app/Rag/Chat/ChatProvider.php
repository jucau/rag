<?php

declare(strict_types=1);

namespace App\Rag\Chat;

use Generator;

/**
 * Generates the final answer of the RAG pipeline (ADR-0010).
 *
 * Implementations receive a system prompt (the grounding instructions)
 * and a user message (retrieved context + question) and return plain text.
 */
interface ChatProvider
{
    public function chat(string $system, string $user): string;

    /**
     * Same as chat(), but yields the answer as text fragments while the
     * model generates them (ADR-0017) — perceived latency drops from
     * "full generation time" to "time to first token".
     *
     * @return Generator<int, string>
     */
    public function chatStream(string $system, string $user): Generator;

    /**
     * Human-readable identifier shown in answers, e.g. "ollama (gemma3:4b)".
     */
    public function describe(): string;
}
