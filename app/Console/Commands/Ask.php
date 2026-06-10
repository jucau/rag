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
    protected $signature = 'rag:ask
        {question : The question to answer from the ingested corpus}
        {--provider= : Override the chat provider ("ollama" or "claude") to compare answers}';

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

        $started = microtime(true);
        $result = $service->ask($question);
        $elapsed = microtime(true) - $started;

        $this->newLine();
        $this->info($result->answer);
        $this->newLine();

        $this->line(sprintf('<comment>Provider:</comment> %s — %.1fs', $result->provider, $elapsed));
        $this->line('<comment>Sources:</comment>');

        $result->chunks
            ->unique(fn (DocumentChunk $chunk): int => $chunk->document_id)
            ->each(function (DocumentChunk $chunk): void {
                $this->line(sprintf('  — %s (%s)', $chunk->document->title, $chunk->document->url));
            });

        return self::SUCCESS;
    }
}
