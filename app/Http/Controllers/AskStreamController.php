<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AskRequest;
use App\Rag\AnswerQuestion;
use App\Rag\Chat\ChatProviderFactory;
use App\Rag\Chat\ChatProviderName;
use App\Rag\Chat\OllamaModel;
use App\Rag\Embeddings\EmbeddingProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AskStreamController extends Controller
{
    public function __invoke(
        AskRequest $request,
        EmbeddingProvider $embeddings,
        ChatProviderFactory $factory,
    ): StreamedResponse {
        $service = new AnswerQuestion(
            embeddings: $embeddings,
            chat: $factory->make(
                provider: $request->enum('provider', ChatProviderName::class),
                model: $request->enum('model', OllamaModel::class),
            ),
            topK: config()->integer('rag.retrieval.top_k'),
            maxDistance: config()->float('rag.retrieval.max_distance'),
        );

        $question = $request->string('question')->value();

        return response()->stream(function () use ($service, $question): void {
            foreach ($service->askStream($question) as $event) {
                // Server-Sent Events wire format: named event + JSON data,
                // blocks separated by a blank line, flushed immediately.
                echo 'event: ' . $event->type->value . "\n";
                echo 'data: ' . json_encode($event->data) . "\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();
            }
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            // Tells reverse proxies (nginx…) not to buffer the stream.
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
