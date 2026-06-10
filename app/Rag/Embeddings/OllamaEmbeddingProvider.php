<?php

declare(strict_types=1);

namespace App\Rag\Embeddings;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final readonly class OllamaEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private string $baseUrl,
        private string $model,
        private int $dimensions,
    ) {
    }

    public function embed(string $text): array
    {
        $embeddings = $this->embedBatch([$text]);

        return $embeddings[0];
    }

    public function embedBatch(array $texts): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->timeout(120)
            ->post('/api/embed', [
                'model' => $this->model,
                'input' => $texts,
            ])
            ->throw();

        /** @var array{embeddings: list<list<float>>} $data */
        $data = $response->json();

        foreach ($data['embeddings'] as $index => $embedding) {
            if (count($embedding) !== $this->dimensions) {
                throw new RuntimeException(sprintf(
                    'Embedding #%d has %d dimensions, expected %d. Check that the configured model matches rag.embeddings.dimensions.',
                    $index,
                    count($embedding),
                    $this->dimensions,
                ));
            }
        }

        return $data['embeddings'];
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }
}
