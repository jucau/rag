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
            // Generation can target a different Ollama than embeddings
            // (ADR-0022): the host's native Ollama gets the Metal GPU,
            // while embeddings stay on the pinned, reproducible
            // dockerised Ollama. Falls back to the embeddings URL.
            'url' => env('OLLAMA_CHAT_URL', env('OLLAMA_URL', 'http://ollama:11434')),
            'model' => env('OLLAMA_CHAT_MODEL', 'llama3.2:3b'),
            // For thinking models (qwen3…): false disables the reasoning
            // phase (faster), true forces it. Leave unset for models
            // without thinking support — they reject the parameter.
            'think' => env('OLLAMA_CHAT_THINK') === null
                ? null
                : filter_var(env('OLLAMA_CHAT_THINK'), FILTER_VALIDATE_BOOL),
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

        // Chunks farther than this cosine distance are considered noise.
        // Calibrated on 2026-06-11: relevant questions land at 0.41-0.49,
        // off-topic ones at 0.77+ (ADR-0014).
        'max_distance' => (float) env('RAG_MAX_DISTANCE', 0.65),
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
