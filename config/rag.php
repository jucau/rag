<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Which provider turns text into vectors, and the expected vector size.
    | The dimensions value is structural: it must match the vector column
    | defined in the database, and embeddings from different models are
    | never comparable. Changing the model means re-ingesting everything.
    |
    */

    'embeddings' => [
        'provider' => env('RAG_EMBEDDINGS_PROVIDER', 'ollama'),
        'dimensions' => (int) env('RAG_EMBEDDINGS_DIMENSIONS', 768),

        'ollama' => [
            'url' => env('OLLAMA_URL', 'http://ollama:11434'),
            'model' => env('OLLAMA_EMBEDDING_MODEL', 'embeddinggemma'),
        ],
    ],

];
