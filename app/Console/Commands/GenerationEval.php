<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DocumentChunk;
use App\Rag\AnswerQuestion;
use App\Rag\Chat\ChatProviderFactory;
use App\Rag\Chat\ChatProviderName;
use App\Rag\Chat\OllamaModel;
use App\Rag\Embeddings\EmbeddingProvider;
use App\Rag\Evaluation\AnswerGrader;
use App\Rag\Observability\ExecutionLog;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Instruments the GENERATION phase, conditioned on retrieval quality
 * (ADR-0026). rag:retrieval-eval showed the right document reaches the
 * top-k for ~95 % of questions — so when an answer is still wrong, it is
 * the generator's fault. This command makes that precise.
 *
 * For every answerable question it runs the real pipeline, then crosses
 * two deterministic signals:
 *   - was the context good?  (a retrieved chunk belongs to the gold
 *     document of benchmark/retrieval-gold.json — i.e. the model was
 *     handed the answer)
 *   - what did the model do?  (AnswerGrader: correct / refusal / wrong,
 *     graded against benchmark/answer-keys.json, no LLM judge)
 *
 * The headline is the GOOD-CONTEXT bucket: of the questions where the
 * answer was in the prompt, how many did the model convert, how many did
 * it over-refuse, how many did it misread? That isolates generation from
 * retrieval. Trap/off-corpus/biased questions are reported separately as
 * a refusal-discipline (caution) stat.
 */
class GenerationEval extends Command
{
    #[\Override]
    protected $signature = 'rag:generation-eval
        {--questions=benchmark/questions.json : The question set}
        {--gold=benchmark/retrieval-gold.json : Retrieval relevance judgments (context-good signal)}
        {--keys=benchmark/answer-keys.json : Accepted answer aliases (correctness signal)}
        {--model=* : Models to evaluate (default: llama3.2:3b and gemma3:4b)}';

    #[\Override]
    protected $description = 'Instrument generation conditioned on retrieval: conversion, over-refusal, misread';

    public function handle(EmbeddingProvider $embeddings, ChatProviderFactory $factory): int
    {
        $questions = $this->loadQuestions();
        $gold = $this->loadMap($this->stringOption('gold'), 'judgments');
        $keys = $this->loadMap($this->stringOption('keys'), 'keys');

        if ($questions === [] || $gold === [] || $keys === []) {
            $this->error('Could not load questions, gold judgments and/or answer keys.');

            return self::FAILURE;
        }

        $models = $this->models();

        if ($models === null) {
            return self::FAILURE;
        }

        $grader = new AnswerGrader();
        $log = new ExecutionLog('rag:generation-eval', [
            'models' => array_map(fn (OllamaModel $m): string => $m->value, $models),
            'judged' => count($keys),
        ]);
        $this->line(sprintf('<comment>Live log (tail -f):</comment> %s', $log->livePath()));

        /** @var array<string, array<string, int>> $stats */
        $stats = [];

        foreach ($models as $model) {
            $this->newLine();
            $this->info(sprintf('═══ %s ═══', $model->value));

            $chat = $factory->make(ChatProviderName::Ollama, $model);
            $chat->chat('Réponds uniquement "ok".', 'ok'); // untimed warm-up

            $service = new AnswerQuestion(
                embeddings: $embeddings,
                chat: $chat,
                topK: config()->integer('rag.retrieval.top_k'),
                maxDistance: config()->float('rag.retrieval.max_distance'),
            );

            $stats[$model->value] = $this->evaluateModel($model, $service, $grader, $questions, $gold, $keys, $log);
        }

        $this->report($stats);
        $path = $log->finish('success', $stats);
        $this->line(sprintf('<comment>Execution log:</comment> %s', $path));

        return self::SUCCESS;
    }

    /**
     * @param list<array{id: string, category: string, question: string, expected: string}> $questions
     * @param array<string, list<string>> $gold
     * @param array<string, list<string>> $keys
     * @return array<string, int>
     */
    private function evaluateModel(
        OllamaModel $model,
        AnswerQuestion $service,
        AnswerGrader $grader,
        array $questions,
        array $gold,
        array $keys,
        ExecutionLog $log,
    ): array {
        $counts = [
            'good_correct' => 0, 'good_refusal' => 0, 'good_wrong' => 0,
            'poor_correct' => 0, 'poor_refusal' => 0, 'poor_wrong' => 0,
            'trap_refused' => 0, 'trap_answered' => 0,
        ];

        foreach ($questions as $question) {
            $id = $question['id'];

            try {
                $result = $service->ask($question['question']);
            } catch (Throwable $throwable) {
                $this->warn(sprintf('⚠ [%s][%s] failed: %s', $model->value, $id, $throwable->getMessage()));
                $log->event('error', ['model' => $model->value, 'question_id' => $id, 'error' => mb_substr($throwable->getMessage(), 0, 300)]);

                continue;
            }

            $answer = $result->answer;
            $answerKeys = $keys[$id] ?? null;

            if ($answerKeys === null) {
                // Trap / off-corpus / biased: the right move is a refusal
                // or a correction; we report refusal discipline only.
                $refused = $grader->isRefusal($answer);
                $counts[$refused ? 'trap_refused' : 'trap_answered']++;
                $log->event('trap', $this->eventData($model, $question, $answer, null, $refused ? 'refused' : 'answered'));

                continue;
            }

            $contextGood = $this->contextIsGood($result->chunks, $gold[$id] ?? []);
            $outcome = $grader->matchesKey($answer, $answerKeys)
                ? 'correct'
                : ($grader->isRefusal($answer) ? 'refusal' : 'wrong');

            $bucket = ($contextGood ? 'good_' : 'poor_') . $outcome;
            $counts[$bucket]++;

            $this->line(sprintf(
                '  [%s] %-4s %s %-8s %s',
                $contextGood ? 'ctx✓' : 'ctx✗',
                $id,
                $this->icon($outcome),
                $outcome,
                mb_substr(str_replace("\n", ' ', $answer), 0, 70),
            ));
            $log->event('graded', $this->eventData($model, $question, $answer, $contextGood, $outcome));
        }

        return $counts;
    }

    /**
     * Was the answer document actually in the context shown to the model?
     *
     * @param Collection<int, DocumentChunk> $chunks
     * @param list<string> $relevant
     */
    private function contextIsGood(Collection $chunks, array $relevant): bool
    {
        if ($relevant === []) {
            return false;
        }

        return $chunks->contains(
            fn (DocumentChunk $chunk): bool => in_array($chunk->document->title, $relevant, true),
        );
    }

    /**
     * @param array{id: string, category: string, question: string, expected: string} $question
     * @return array<string, mixed>
     */
    private function eventData(OllamaModel $model, array $question, string $answer, ?bool $contextGood, string $outcome): array
    {
        return [
            'model' => $model->value,
            'question_id' => $question['id'],
            'category' => $question['category'],
            'question' => $question['question'],
            'expected' => $question['expected'],
            'context_good' => $contextGood,
            'outcome' => $outcome,
            'answer' => $answer,
        ];
    }

    /**
     * @param array<string, array<string, int>> $stats
     */
    private function report(array $stats): void
    {
        $this->newLine();
        $this->info('Generation, conditioned on retrieval:');

        $rows = [];
        foreach ($stats as $model => $c) {
            $good = $c['good_correct'] + $c['good_refusal'] + $c['good_wrong'];
            $poor = $c['poor_correct'] + $c['poor_refusal'] + $c['poor_wrong'];
            $traps = $c['trap_refused'] + $c['trap_answered'];

            $rows[] = [
                $model,
                sprintf('%d/%d (%.0f%%)', $c['good_correct'], $good, $good > 0 ? $c['good_correct'] / $good * 100 : 0),
                (string) $c['good_refusal'],
                (string) $c['good_wrong'],
                sprintf('%d/%d', $c['poor_correct'], $poor),
                sprintf('%d/%d (%.0f%%)', $c['trap_refused'], $traps, $traps > 0 ? $c['trap_refused'] / $traps * 100 : 0),
            ];
        }

        $this->table(
            ['model', 'good-ctx conversion', 'over-refusal', 'misread', 'poor-ctx correct', 'trap refusal'],
            $rows,
        );

        $this->line('  <fg=gray>good-ctx conversion</> = correct answers when the answer WAS in the context (pure generation skill).');
        $this->line('  <fg=gray>over-refusal</> = refused despite good context · <fg=gray>misread</> = wrong despite good context.');
        $this->line('  <fg=gray>trap refusal</> = refused on a trap/off-corpus/biased question (caution, higher is better).');
    }

    private function icon(string $outcome): string
    {
        return match ($outcome) {
            'correct' => '<fg=green>✅</>',
            'refusal' => '<fg=yellow>🙈</>',
            default => '<fg=red>❌</>',
        };
    }

    /**
     * @return list<array{id: string, category: string, question: string, expected: string}>
     */
    private function loadQuestions(): array
    {
        $path = base_path($this->stringOption('questions'));

        if (! is_file($path)) {
            $this->error(sprintf('Question file not found: %s', $path));

            return [];
        }

        /** @var list<array{id: string, category: string, question: string, expected: string}>|null $questions */
        $questions = json_decode((string) file_get_contents($path), true);

        return $questions ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function loadMap(string $file, string $rootKey): array
    {
        $path = base_path($file);

        if (! is_file($path)) {
            $this->error(sprintf('File not found: %s', $path));

            return [];
        }

        /** @var array<string, array<string, list<string>>>|null $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);

        return $decoded[$rootKey] ?? [];
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }

    /**
     * @return list<OllamaModel>|null
     */
    private function models(): ?array
    {
        /** @var list<string> $requested */
        $requested = $this->option('model');

        if ($requested === []) {
            return [OllamaModel::Llama32_3B, OllamaModel::Gemma3_4B];
        }

        $models = [];

        foreach ($requested as $value) {
            $model = OllamaModel::tryFrom($value);

            if (! $model instanceof OllamaModel) {
                $this->error(sprintf('Unknown model "%s" (allowed: %s).', $value, implode(', ', array_column(OllamaModel::cases(), 'value'))));

                return null;
            }

            $models[] = $model;
        }

        return $models;
    }
}
