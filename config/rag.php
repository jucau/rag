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

    /*
    |--------------------------------------------------------------------------
    | Chat (generation)
    |--------------------------------------------------------------------------
    |
    | Which provider writes the final answer (ADR-0010). Default is the
    | local Ollama model — free, private, key-less; Claude is the
    | frontier-quality alternative (requires ANTHROPIC_API_KEY).
    |
    */

    'chat' => [
        'provider' => env('RAG_CHAT_PROVIDER', 'ollama'),

        'ollama' => [
            'model' => env('OLLAMA_CHAT_MODEL', 'gemma3:4b'),
        ],

        'claude' => [
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'model' => env('CLAUDE_MODEL', 'claude-opus-4-8'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval
    |--------------------------------------------------------------------------
    */

    'retrieval' => [
        // How many chunks are injected into the generation prompt.
        'top_k' => (int) env('RAG_TOP_K', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Corpus
    |--------------------------------------------------------------------------
    */

    'corpus' => [
        'wikipedia' => [
            'api_url' => env('WIKIPEDIA_API_URL', 'https://fr.wikipedia.org/w/api.php'),
        ],
    ],

];
