<?php

declare(strict_types=1);

namespace App\Rag;

use App\Models\DocumentChunk;
use App\Models\RagQuery;
use App\Rag\Chat\ChatProvider;
use App\Rag\Embeddings\EmbeddingProvider;
use Illuminate\Support\Collection;
use Pgvector\Laravel\Distance;
use Pgvector\Laravel\Vector;

/**
 * The online phase of the RAG pipeline (docs/02-rag-pipeline.md):
 * embed the question → retrieve the top-k closest chunks → build a
 * grounded prompt → let the chat provider write the answer.
 *
 * Each phase is timed and every query is logged to rag_queries
 * (ADR-0011), so "where is it slow?" is answered by data.
 */
final readonly class AnswerQuestion
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        You are a question-answering assistant working over a document corpus.

        Rules:
        - Answer ONLY from the provided context. If the context does not
          contain the answer, say so plainly — never invent facts.
        - Answer in the same language as the question.
        - Be concise and factual.
        - When the context allows it, mention which source(s) you used.
        PROMPT;

    public function __construct(
        private EmbeddingProvider $embeddings,
        private ChatProvider $chat,
        private int $topK,
    ) {
    }

    public function ask(string $question): RagAnswer
    {
        // 1. Embed the question — with the same model as ingestion.
        $started = hrtime(true);
        $vector = new Vector($this->embeddings->embed($question));
        $embeddingMs = $this->elapsedMs($started);

        // 2. Retrieve the top-k chunks by cosine distance (pgvector <=>).
        $started = hrtime(true);
        $chunks = DocumentChunk::query()
            ->nearestNeighbors('embedding', $vector, Distance::Cosine)
            ->with('document')
            ->take($this->topK)
            ->get();
        $retrievalMs = $this->elapsedMs($started);

        // 3. Generate, grounded in the retrieved context.
        $started = hrtime(true);
        $answer = $this->chat->chat(self::SYSTEM_PROMPT, $this->buildUserPrompt($question, $chunks));
        $generationMs = $this->elapsedMs($started);

        $timings = new RagTimings($embeddingMs, $retrievalMs, $generationMs);

        RagQuery::query()->create([
            'question' => $question,
            'answer' => $answer,
            'provider' => $this->chat->describe(),
            'chunk_ids' => $chunks->modelKeys(),
            'embedding_ms' => $timings->embeddingMs,
            'retrieval_ms' => $timings->retrievalMs,
            'generation_ms' => $timings->generationMs,
            'total_ms' => $timings->totalMs(),
        ]);

        return new RagAnswer($answer, $chunks, $this->chat->describe(), $timings);
    }

    private function elapsedMs(int|float $startedNs): int
    {
        return (int) round((hrtime(true) - $startedNs) / 1_000_000);
    }

    /**
     * @param Collection<int, DocumentChunk> $chunks
     */
    private function buildUserPrompt(string $question, Collection $chunks): string
    {
        $context = $chunks
            ->map(fn (DocumentChunk $chunk, int $index): string => sprintf(
                "[Source %d: %s — %s]\n%s",
                $index + 1,
                $chunk->document->title,
                $chunk->document->url,
                $chunk->content,
            ))
            ->implode("\n\n---\n\n");

        return <<<PROMPT
            <context>
            {$context}
            </context>

            Question: {$question}
            PROMPT;
    }
}
