<?php

declare(strict_types=1);

namespace App\Rag\Chat;

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
     * Human-readable identifier shown in answers, e.g. "ollama (gemma3:4b)".
     */
    public function describe(): string;
}
