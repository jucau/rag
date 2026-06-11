<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Rag\AnswerQuestion;
use App\Rag\Chat\ChatProviderFactory;
use App\Rag\Chat\ChatProviderName;
use App\Rag\Chat\OllamaModel;
use App\Rag\Embeddings\EmbeddingProvider;
use Illuminate\Console\Command;

class Ask extends Command
{
    #[\Override]
    protected $signature = 'rag:ask
        {question : The question to answer from the ingested corpus}
        {--provider= : Override the chat provider ("ollama" or "claude") to compare answers}
        {--model= : Override the local model (e.g. "llama3.2:3b") — see OllamaModel for the whitelist}';

    #[\Override]
    protected $description = 'Answer a question using the RAG pipeline (retrieve + generate)';

    public function handle(
        EmbeddingProvider $embeddings,
        ChatProviderFactory $factory,
    ): int {
        /** @var string $question */
        $question = $this->argument('question');

        /** @var string|null $providerOption */
        $providerOption = $this->option('provider');
        $provider = $providerOption === null ? null : ChatProviderName::tryFrom($providerOption);

        if ($providerOption !== null && ! $provider instanceof ChatProviderName) {
            $this->error(sprintf('Unknown provider "%s".', $providerOption));

            return self::FAILURE;
        }

        /** @var string|null $modelOption */
        $modelOption = $this->option('model');
        $model = $modelOption === null ? null : OllamaModel::tryFrom($modelOption);

        if ($modelOption !== null && ! $model instanceof OllamaModel) {
            $this->error(sprintf(
                'Unknown model "%s" (allowed: %s).',
                $modelOption,
                implode(', ', array_column(OllamaModel::cases(), 'value')),
            ));

            return self::FAILURE;
        }

        $service = new AnswerQuestion(
            embeddings: $embeddings,
            chat: $factory->make($provider, $model),
            topK: config()->integer('rag.retrieval.top_k'),
            maxDistance: config()->float('rag.retrieval.max_distance'),
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

        $documents = collect($result->sources())->unique('url');

        foreach ($documents as $source) {
            $this->line(sprintf('  — %s (%s)', $source['title'], $source['url']));
        }

        return self::SUCCESS;
    }
}
