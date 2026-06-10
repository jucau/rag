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
 * (ADR-0011). Retrieval is guarded by a relevance threshold
 * (ADR-0014): off-corpus questions are answered without calling the
 * generation model at all.
 */
final readonly class AnswerQuestion
{
    public const string OUT_OF_CORPUS_ANSWER = "Cette question semble hors du corpus : aucun passage suffisamment proche n'a été trouvé. Essayez une question sur le football français (Ligue 1, clubs, joueurs…).";

    private const string SYSTEM_PROMPT = <<<'PROMPT'
        You are a question-answering assistant working over a French
        football document corpus.

        Rules:
        - Answer ONLY from the provided context. State a fact only if it
          is explicitly written there. If the exact information is
          missing, say so plainly — never guess, and never turn a vague
          description into a precise fact.
        - Every context block starts with its origin, e.g.
          [Article : Olympique lyonnais — Section : Stades]. A fact about
          an entity must come from a block whose article is about that
          entity. When sources conflict, prefer the article dedicated to
          the question's subject and mention the conflict explicitly.
        - Answer in French, unless the question is clearly written in
          another language.
        - Be concise and factual. Cite the sources you used.
        PROMPT;

    public function __construct(
        private EmbeddingProvider $embeddings,
        private ChatProvider $chat,
        private int $topK,
        private float $maxDistance,
    ) {
    }

    public function ask(string $question): RagAnswer
    {
        // 1. Embed the question — with the same model as ingestion.
        $started = hrtime(true);
        $vector = new Vector($this->embeddings->embed($question));
        $embeddingMs = $this->elapsedMs($started);

        // 2. Retrieve the top-k chunks by cosine distance (pgvector <=>),
        //    keeping only those close enough to be meaningful (ADR-0014).
        $started = hrtime(true);
        $chunks = DocumentChunk::query()
            ->nearestNeighbors('embedding', $vector, Distance::Cosine)
            ->with('document')
            ->take($this->topK)
            ->get()
            ->filter(fn (DocumentChunk $chunk): bool => $this->distanceOf($chunk) <= $this->maxDistance)
            ->values();
        $retrievalMs = $this->elapsedMs($started);

        // 3. Generate, grounded in the retrieved context. If nothing
        //    relevant was retrieved, don't call the model at all: it
        //    could only hallucinate from off-topic passages.
        $started = hrtime(true);

        if ($chunks->isEmpty()) {
            $answer = self::OUT_OF_CORPUS_ANSWER;
            $provider = 'guard (out-of-corpus)';
        } else {
            $answer = $this->chat->chat(self::SYSTEM_PROMPT, $this->buildUserPrompt($question, $chunks));
            $provider = $this->chat->describe();
        }

        $generationMs = $this->elapsedMs($started);

        $timings = new RagTimings($embeddingMs, $retrievalMs, $generationMs);
        $ragAnswer = new RagAnswer($answer, $chunks, $provider, $timings);

        RagQuery::query()->create([
            'question' => $question,
            'answer' => $answer,
            'provider' => $ragAnswer->provider,
            'sources' => $ragAnswer->sources(),
            'embedding_ms' => $timings->embeddingMs,
            'retrieval_ms' => $timings->retrievalMs,
            'generation_ms' => $timings->generationMs,
            'total_ms' => $timings->totalMs(),
        ]);

        return $ragAnswer;
    }

    private function elapsedMs(int|float $startedNs): int
    {
        return (int) round((hrtime(true) - $startedNs) / 1_000_000);
    }

    /**
     * Cosine distance computed by the nearestNeighbors scope.
     */
    private function distanceOf(DocumentChunk $chunk): float
    {
        return $chunk->neighbor_distance;
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
