<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AskRequest;
use App\Rag\AnswerQuestion;
use App\Rag\Chat\ChatProviderFactory;
use App\Rag\Embeddings\EmbeddingProvider;
use Illuminate\Http\JsonResponse;

class AskController extends Controller
{
    public function __invoke(
        AskRequest $request,
        EmbeddingProvider $embeddings,
        ChatProviderFactory $factory,
    ): JsonResponse {
        /** @var string|null $provider */
        $provider = $request->validated('provider');

        $service = new AnswerQuestion(
            embeddings: $embeddings,
            chat: $factory->make($provider),
            topK: config()->integer('rag.retrieval.top_k'),
        );

        $result = $service->ask($request->string('question')->value());

        return response()->json([
            'answer' => $result->answer,
            'provider' => $result->provider,
            'sources' => $result->sources(),
            'timings' => [
                'embedding_ms' => $result->timings->embeddingMs,
                'retrieval_ms' => $result->timings->retrievalMs,
                'generation_ms' => $result->timings->generationMs,
                'total_ms' => $result->timings->totalMs(),
            ],
        ]);
    }
}
