<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DocumentChunk;
use App\Rag\Embeddings\EmbeddingProvider;
use App\Rag\Evaluation\AnswerGrader;
use App\Rag\Observability\ExecutionLog;
use Illuminate\Console\Command;
use Pgvector\Laravel\Distance;
use Pgvector\Laravel\Vector;
use Throwable;

/**
 * Measures retrieval quality in isolation, separate from generation
 * (ADR-0025). For every answerable benchmark question we know which
 * document(s) hold the answer (benchmark/retrieval-gold.json, judged at
 * the document level). This command embeds each question, retrieves a
 * deep pool of chunks by cosine distance, and reports where the first
 * relevant chunk lands:
 *
 *   - recall@k  — share of questions whose answer document appears in
 *                 the top-k. recall@5 is what production sees today;
 *                 recall@20 is the ceiling a reranker could reach.
 *   - MRR       — mean reciprocal rank of the first relevant chunk;
 *                 rewards ranking the right chunk high, not just present.
 *
 * Both are reported at two granularities (ADR-0026): document-level (the
 * right *article* is retrieved) and fact-level (a retrieved chunk's
 * *content* actually states the answer, via the answer aliases). The gap
 * between them quantifies how lenient document-level judging is.
 *
 * No generation model is loaded: the benchmark (rag:benchmark) grades
 * the answer text; this grades the *context* the model is handed. A low
 * recall here caps the whole pipeline no matter how good the model is.
 */
class RetrievalEval extends Command
{
    #[\Override]
    protected $signature = 'rag:retrieval-eval
        {--questions=benchmark/questions.json : The question set}
        {--gold=benchmark/retrieval-gold.json : The relevance judgments (qrels)}
        {--keys=benchmark/answer-keys.json : Answer aliases, for fact-level recall}
        {--pool=20 : How many chunks to retrieve per question (the deep pool)}';

    #[\Override]
    protected $description = 'Measure retrieval quality (recall@k, MRR) against the relevance judgments';

    /**
     * The cut-offs reported. top_k (production) and the pool size are
     * always included on top of these defaults.
     *
     * @var list<int>
     */
    private const array RECALL_CUTOFFS = [1, 3, 5, 10, 20];

    public function handle(EmbeddingProvider $embeddings, AnswerGrader $grader): int
    {
        $pool = max(1, (int) $this->option('pool'));
        $topK = config()->integer('rag.retrieval.top_k');
        $maxDistance = config()->float('rag.retrieval.max_distance');

        $questions = $this->loadQuestions();
        $gold = $this->loadGold();
        $keys = $this->loadKeys();

        if ($questions === [] || $gold === []) {
            $this->error('Could not load questions and/or gold judgments.');

            return self::FAILURE;
        }

        $cutoffs = $this->cutoffs($pool, $topK);

        $log = new ExecutionLog('rag:retrieval-eval', [
            'pool' => $pool,
            'top_k' => $topK,
            'max_distance' => $maxDistance,
            'judged_questions' => count($gold),
        ]);
        $this->line(sprintf('<comment>Live log (tail -f):</comment> %s', $log->livePath()));

        $firstRanks = [];
        $factRanks = [];
        $thresholdDrops = 0;

        /** @var array<string, array{judged: int, hit5: int}> $byCategory */
        $byCategory = [];

        $this->newLine();
        $this->line('  <fg=gray>id   cat                  doc  fact  dist   in@5  kept  question</>');

        foreach ($questions as $question) {
            $id = $question['id'];
            $relevant = $gold[$id] ?? null;

            if ($relevant === null) {
                continue; // not a retrieval question (trap / off-corpus / biased)
            }

            try {
                $ranked = $this->retrieve($embeddings, $question['question'], $pool);
            } catch (Throwable $throwable) {
                $this->warn(sprintf('⚠ [%s] retrieval failed: %s', $id, $throwable->getMessage()));
                $log->event('error', ['question_id' => $id, 'error' => mb_substr($throwable->getMessage(), 0, 300)]);

                continue;
            }

            [$firstRank, $firstDistance] = $this->firstRelevant($ranked, $relevant);
            $firstRanks[] = $firstRank;

            // Fact-level rank: the first chunk whose CONTENT actually
            // carries the answer (an accepted alias), not merely the right
            // article. Quantifies how lenient the document-level recall is.
            $factRank = $this->firstFact($ranked, $grader, $keys[$id] ?? []);
            $factRanks[] = $factRank;

            $category = $question['category'];
            $byCategory[$category] ??= ['judged' => 0, 'hit5' => 0];
            ++$byCategory[$category]['judged'];

            $inTopK = $firstRank !== null && $firstRank <= $topK;
            $kept = $inTopK && $firstDistance !== null && $firstDistance <= $maxDistance;

            if ($inTopK) {
                ++$byCategory[$category]['hit5'];
            }

            // A relevant chunk ranked inside the prompt window but cut by
            // the distance guard: retrieval found it, the threshold hid it.
            if ($inTopK && ! $kept) {
                ++$thresholdDrops;
            }

            $this->line(sprintf(
                '  %-4s %-20s %4s %5s %6s   %-4s  %-4s  %s',
                $id,
                mb_substr($category, 0, 20),
                $firstRank ?? '—',
                $factRank ?? '<fg=yellow>—</>',
                $firstDistance !== null ? number_format($firstDistance, 3) : '—',
                $inTopK ? 'yes' : '<fg=red>NO</>',
                $firstRank === null ? '—' : ($kept ? 'yes' : '<fg=yellow>cut</>'),
                mb_substr($question['question'], 0, 46),
            ));

            $log->event('query', [
                'question_id' => $id,
                'category' => $category,
                'question' => $question['question'],
                'relevant_docs' => $relevant,
                'first_relevant_rank' => $firstRank,
                'first_relevant_distance' => $firstDistance,
                'first_fact_rank' => $factRank,
                'in_top_k' => $inTopK,
                'kept_after_threshold' => $kept,
                'top1_doc' => $ranked[0]['title'] ?? null,
                'top1_distance' => $ranked[0]['distance'] ?? null,
            ]);
        }

        $summary = $this->summarize($firstRanks, $factRanks, $cutoffs, $thresholdDrops, $topK, $maxDistance, $byCategory);
        $this->report($summary, $cutoffs, $topK);

        $path = $log->finish('success', $summary);
        $this->line(sprintf('<comment>Execution log:</comment> %s', $path));

        return self::SUCCESS;
    }

    /**
     * Retrieve the deep pool, ranked by ascending cosine distance, as a
     * plain list of {title, distance} — no relevance threshold applied
     * (we want to see where a relevant chunk lands even if the guard
     * would later cut it).
     *
     * @return list<array{title: string, distance: float, content: string}>
     */
    private function retrieve(EmbeddingProvider $embeddings, string $question, int $pool): array
    {
        $vector = new Vector($embeddings->embed($question));

        $ranked = DocumentChunk::query()
            ->nearestNeighbors('embedding', $vector, Distance::Cosine)
            ->with('document')
            ->take($pool)
            ->get()
            ->map(fn (DocumentChunk $chunk): array => [
                'title' => $chunk->document->title,
                'distance' => $chunk->neighbor_distance,
                'content' => $chunk->content,
            ])
            ->all();

        return array_values($ranked);
    }

    /**
     * The 1-based rank of the first chunk whose document is relevant, and
     * its distance — or [null, null] if no relevant chunk is in the pool.
     *
     * @param list<array{title: string, distance: float, content: string}> $ranked
     * @param list<string> $relevant
     * @return array{int|null, float|null}
     */
    private function firstRelevant(array $ranked, array $relevant): array
    {
        foreach ($ranked as $index => $chunk) {
            if (in_array($chunk['title'], $relevant, true)) {
                return [$index + 1, $chunk['distance']];
            }
        }

        return [null, null];
    }

    /**
     * The 1-based rank of the first chunk whose CONTENT carries the
     * answer (matches an accepted alias) — a content-level relevance,
     * stricter than the document-level one. null when no chunk in the
     * pool actually states the fact (or no keys are defined).
     *
     * @param list<array{title: string, distance: float, content: string}> $ranked
     * @param list<string> $keys
     */
    private function firstFact(array $ranked, AnswerGrader $grader, array $keys): ?int
    {
        if ($keys === []) {
            return null;
        }

        foreach ($ranked as $index => $chunk) {
            if ($grader->matchesKey($chunk['content'], $keys)) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * @param list<int|null> $firstRanks
     * @param list<int|null> $factRanks
     * @param list<int> $cutoffs
     * @param array<string, array{judged: int, hit5: int}> $byCategory
     * @return array{judged_questions: int, recall: array<string, float>, recall_fact: array<string, float>, mrr: float, mrr_fact: float, misses_outside_pool: int, fact_misses_outside_pool: int, top_k: int, max_distance: float, threshold_drops_in_top_k: int, by_category: array<string, array{judged: int, hits: int, recall: float}>}
     */
    private function summarize(
        array $firstRanks,
        array $factRanks,
        array $cutoffs,
        int $thresholdDrops,
        int $topK,
        float $maxDistance,
        array $byCategory,
    ): array {
        $n = count($firstRanks);

        [$recall, $mrr, $misses] = $this->rankMetrics($firstRanks, $cutoffs);
        [$recallFact, $mrrFact, $factMisses] = $this->rankMetrics($factRanks, $cutoffs);

        $categoryRecall = [];
        foreach ($byCategory as $category => $counts) {
            $categoryRecall[$category] = [
                'judged' => $counts['judged'],
                'hits' => $counts['hit5'],
                'recall' => $counts['judged'] > 0 ? round($counts['hit5'] / $counts['judged'], 4) : 0.0,
            ];
        }

        return [
            'judged_questions' => $n,
            'recall' => $recall,
            'recall_fact' => $recallFact,
            'mrr' => $mrr,
            'mrr_fact' => $mrrFact,
            'misses_outside_pool' => $misses,
            'fact_misses_outside_pool' => $factMisses,
            'top_k' => $topK,
            'max_distance' => $maxDistance,
            'threshold_drops_in_top_k' => $thresholdDrops,
            'by_category' => $categoryRecall,
        ];
    }

    /**
     * recall@k (per cut-off), MRR and out-of-pool misses for one ranking.
     *
     * @param list<int|null> $ranks
     * @param list<int> $cutoffs
     * @return array{0: array<string, float>, 1: float, 2: int}
     */
    private function rankMetrics(array $ranks, array $cutoffs): array
    {
        $n = count($ranks);

        $recall = [];
        foreach ($cutoffs as $k) {
            $hits = count(array_filter($ranks, fn (?int $rank): bool => $rank !== null && $rank <= $k));
            $recall['recall@' . $k] = $n > 0 ? round($hits / $n, 4) : 0.0;
        }

        $reciprocal = array_map(fn (?int $rank): float => $rank === null ? 0.0 : 1 / $rank, $ranks);
        $mrr = $n > 0 ? round(array_sum($reciprocal) / $n, 4) : 0.0;

        $misses = count(array_filter($ranks, static fn (?int $rank): bool => $rank === null));

        return [$recall, $mrr, $misses];
    }

    /**
     * @param array{judged_questions: int, recall: array<string, float>, recall_fact: array<string, float>, mrr: float, mrr_fact: float, misses_outside_pool: int, fact_misses_outside_pool: int, top_k: int, max_distance: float, threshold_drops_in_top_k: int, by_category: array<string, array{judged: int, hits: int, recall: float}>} $summary
     * @param list<int> $cutoffs
     */
    private function report(array $summary, array $cutoffs, int $topK): void
    {
        $recall = $summary['recall'];
        $recallFact = $summary['recall_fact'];

        $this->newLine();
        $this->info(sprintf('Retrieval over %d judged question(s):', $summary['judged_questions']));

        $rows = [];
        foreach ($cutoffs as $k) {
            $doc = $recall['recall@' . $k] ?? 0.0;
            $fact = $recallFact['recall@' . $k] ?? 0.0;
            $rows[] = [
                sprintf('recall@%d', $k),
                sprintf('%5.1f %%', $doc * 100),
                sprintf('%5.1f %%', $fact * 100),
                sprintf('%+4.1f', ($fact - $doc) * 100),
            ];
        }

        $rows[] = ['MRR', (string) $summary['mrr'], (string) $summary['mrr_fact'], ''];
        $this->table(['metric', 'document-level', 'fact-level', 'Δ pts'], $rows);

        $this->line('  <fg=gray>document-level</> = right article in top-k · <fg=gray>fact-level</> = a chunk whose CONTENT states the answer.');
        $this->line(sprintf(
            '  The gap is the leniency of document-level judging: %d question(s) retrieve the right article but no chunk that actually carries the fact.',
            $summary['fact_misses_outside_pool'] - $summary['misses_outside_pool'],
        ));

        $this->newLine();
        $this->line(sprintf(
            '  <comment>%d</comment> question(s) have no relevant chunk anywhere in the pool (true retrieval misses).',
            $summary['misses_outside_pool'],
        ));
        $this->line(sprintf(
            '  <comment>%d</comment> question(s) have a relevant chunk inside the top-%d but cut by the %.2f distance guard.',
            $summary['threshold_drops_in_top_k'],
            $topK,
            $summary['max_distance'],
        ));

        $this->newLine();
        $this->line(sprintf('  <fg=gray>recall@%d by category:</>', $topK));

        foreach ($summary['by_category'] as $category => $counts) {
            $this->line(sprintf(
                '    %-22s %5.1f %%  (%d judged)',
                $category,
                $counts['recall'] * 100,
                $counts['judged'],
            ));
        }
    }

    /**
     * The reported recall cut-offs: the defaults plus production top_k and
     * the pool ceiling, de-duplicated, sorted, and clamped to the pool.
     *
     * @return list<int>
     */
    private function cutoffs(int $pool, int $topK): array
    {
        $cutoffs = self::RECALL_CUTOFFS;
        $cutoffs[] = $topK;
        $cutoffs[] = $pool;
        $cutoffs = array_values(array_unique(array_filter($cutoffs, fn (int $k): bool => $k <= $pool)));
        sort($cutoffs);

        return $cutoffs;
    }

    /**
     * @return list<array{id: string, category: string, question: string, expected: string}>
     */
    private function loadQuestions(): array
    {
        /** @var string $file */
        $file = $this->option('questions');
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
     * @return array<string, list<string>>
     */
    private function loadGold(): array
    {
        /** @var string $file */
        $file = $this->option('gold');
        $path = base_path($file);

        if (! is_file($path)) {
            $this->error(sprintf('Gold file not found: %s', $path));

            return [];
        }

        /** @var array{judgments?: array<string, list<string>>}|null $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);

        return $decoded['judgments'] ?? [];
    }

    /**
     * Accepted answer aliases per question (benchmark/answer-keys.json),
     * reused here to detect whether a retrieved chunk's content actually
     * states the answer (fact-level recall). Empty if the file is absent.
     *
     * @return array<string, list<string>>
     */
    private function loadKeys(): array
    {
        /** @var string $file */
        $file = $this->option('keys');
        $path = base_path($file);

        if (! is_file($path)) {
            return [];
        }

        /** @var array{keys?: array<string, list<string>>}|null $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);

        return $decoded['keys'] ?? [];
    }
}
