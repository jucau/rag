<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Rag\AnswerQuestion;
use App\Rag\Chat\ChatProviderFactory;
use App\Rag\Chat\ChatProviderName;
use App\Rag\Chat\OllamaModel;
use App\Rag\Embeddings\EmbeddingProvider;
use App\Rag\Observability\ExecutionLog;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs the versioned question set (benchmark/questions.json) against
 * local models. Every run goes through the exact production pipeline
 * (AnswerQuestion) and is therefore logged to rag_queries — the
 * analysis joins question text × provider afterwards.
 */
class Benchmark extends Command
{
    #[\Override]
    protected $signature = 'rag:benchmark
        {--file=benchmark/questions.json : The question set to run}
        {--model=* : Restrict to specific models (default: every OllamaModel case)}';

    #[\Override]
    protected $description = 'Run the benchmark question set against local models, logging every run';

    /**
     * @var array<string, int>
     */
    private array $stats = ['runs' => 0];

    public function handle(
        EmbeddingProvider $embeddings,
        ChatProviderFactory $factory,
    ): int {
        $log = new ExecutionLog('rag:benchmark', [
            'file' => $this->option('file'),
            'models' => $this->option('model'),
        ]);

        $this->line(sprintf('<comment>Live log (tail -f):</comment> %s', $log->livePath()));

        try {
            $exit = $this->performRun($embeddings, $factory, $log);
            $path = $log->finish($exit === self::SUCCESS ? 'success' : 'failure', $this->stats);
        } catch (Throwable $throwable) {
            $log->finish('failure', $this->stats, $throwable->getMessage());

            throw $throwable;
        }

        $this->line(sprintf('<comment>Execution log:</comment> %s', $path));

        return $exit;
    }

    private function performRun(
        EmbeddingProvider $embeddings,
        ChatProviderFactory $factory,
        ExecutionLog $log,
    ): int {
        $questions = $this->loadQuestions();

        if ($questions === []) {
            $this->error('No questions loaded.');

            return self::FAILURE;
        }

        $models = $this->models();

        if ($models === null) {
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Benchmark: %d question(s) × %d model(s) = %d runs.',
            count($questions),
            count($models),
            count($questions) * count($models),
        ));

        $startedAt = hrtime(true);

        foreach ($models as $model) {
            $this->newLine();
            $this->info(sprintf('═══ %s ═══', $model->value));

            $chat = $factory->make(ChatProviderName::Ollama, $model);

            // Untimed warm-up: loading the model must not pollute the
            // first question's timing.
            $chat->chat('Réponds uniquement "ok".', 'ok');

            $service = new AnswerQuestion(
                embeddings: $embeddings,
                chat: $chat,
                topK: config()->integer('rag.retrieval.top_k'),
                maxDistance: config()->float('rag.retrieval.max_distance'),
            );

            foreach ($questions as $question) {
                $result = $service->ask($question['question']);

                $this->line(sprintf(
                    '[%s][%s] %4.1fs — %s',
                    $model->value,
                    $question['id'],
                    $result->timings->totalMs() / 1000,
                    mb_substr(str_replace("\n", ' ', $result->answer), 0, 110),
                ));
                $log->event('run', [
                    'model' => $model->value,
                    'question_id' => $question['id'],
                    'category' => $question['category'],
                    'question' => $question['question'],
                    'expected' => $question['expected'],
                    'answer' => $result->answer,
                    'total_ms' => $result->timings->totalMs(),
                    'generation_ms' => $result->timings->generationMs,
                ]);

                ++$this->stats['runs'];
                $this->stats[$model->value] = ($this->stats[$model->value] ?? 0) + 1;
            }
        }

        $elapsed = (int) round((hrtime(true) - $startedAt) / 1_000_000_000);
        $this->newLine();
        $this->info(sprintf('Benchmark done in %dm%02ds.', intdiv($elapsed, 60), $elapsed % 60));

        return self::SUCCESS;
    }

    /**
     * @return list<array{id: string, category: string, question: string, expected: string}>
     */
    private function loadQuestions(): array
    {
        /** @var string $file */
        $file = $this->option('file');
        $path = base_path($file);

        if (! is_file($path)) {
            $this->error(sprintf('Question file not found: %s', $path));

            return [];
        }

        /** @var list<array{id: string, category: string, question: string, expected: string}>|null $questions */
        $questions = json_decode((string) file_get_contents($path), true);

        return $questions ?? [];
    }

    /**
     * @return list<OllamaModel>|null
     */
    private function models(): ?array
    {
        /** @var list<string> $requested */
        $requested = $this->option('model');

        if ($requested === []) {
            return OllamaModel::cases();
        }

        $models = [];

        foreach ($requested as $value) {
            $model = OllamaModel::tryFrom($value);

            if (! $model instanceof OllamaModel) {
                $this->error(sprintf(
                    'Unknown model "%s" (allowed: %s).',
                    $value,
                    implode(', ', array_column(OllamaModel::cases(), 'value')),
                ));

                return null;
            }

            $models[] = $model;
        }

        return $models;
    }
}
