<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DocumentChunk;
use App\Rag\AnswerQuestion;
use App\Rag\Chat\ChatProviderFactory;
use App\Rag\Embeddings\EmbeddingProvider;
use Illuminate\Console\Command;

class Ask extends Command
{
    #[\Override]
    protected $signature = 'rag:ask
        {question : The question to answer from the ingested corpus}
        {--provider= : Override the chat provider ("ollama" or "claude") to compare answers}';

    #[\Override]
    protected $description = 'Answer a question using the RAG pipeline (retrieve + generate)';

    public function handle(
        EmbeddingProvider $embeddings,
        ChatProviderFactory $factory,
    ): int {
        /** @var string $question */
        $question = $this->argument('question');

        /** @var string|null $provider */
        $provider = $this->option('provider');

        $service = new AnswerQuestion(
            embeddings: $embeddings,
            chat: $factory->make($provider),
            topK: config()->integer('rag.retrieval.top_k'),
        );

        $result = $service->ask($question);

        $this->newLine();
        $this->info($result->answer);
        $this->newLine();

        $timings = $result->timings;
        $this->line(sprintf(
            '<comment>Provider:</comment> %s — %.1fs (embedding %dms | retrieval %dms | generation %dms)',
            $result->provider,
            $timings->totalMs() / 1000,
            $timings->embeddingMs,
            $timings->retrievalMs,
            $timings->generationMs,
        ));
        $this->line('<comment>Sources:</comment>');

        $result->chunks
            ->unique(fn (DocumentChunk $chunk): int => $chunk->document_id)
            ->each(function (DocumentChunk $chunk): void {
                $this->line(sprintf('  — %s (%s)', $chunk->document->title, $chunk->document->url));
            });

        return self::SUCCESS;
    }
}
