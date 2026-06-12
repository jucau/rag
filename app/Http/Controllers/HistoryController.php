<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RagQuery;
use Inertia\Inertia;
use Inertia\Response;

class HistoryController extends Controller
{
    public function __invoke(): Response
    {
        $queries = RagQuery::query()
            ->orderByDesc('id')
            ->paginate(15)
            ->through(fn (RagQuery $query): array => [
                'id' => $query->id,
                'question' => $query->question,
                'answer' => $query->answer,
                'provider' => $query->provider,
                'sources' => $query->sources,
                'timings' => [
                    'embedding_ms' => $query->embedding_ms,
                    'retrieval_ms' => $query->retrieval_ms,
                    'generation_ms' => $query->generation_ms,
                    'total_ms' => $query->total_ms,
                ],
                'created_at' => $query->created_at?->format('d/m/Y H:i'),
            ]);

        return Inertia::render('History', ['queries' => $queries]);
    }
}
