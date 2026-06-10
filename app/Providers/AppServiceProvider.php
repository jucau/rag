<?php

declare(strict_types=1);

namespace App\Providers;

use App\Rag\Embeddings\EmbeddingProvider;
use App\Rag\Embeddings\OllamaEmbeddingProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        // Driver pattern: the app depends on the EmbeddingProvider contract,
        // the concrete provider is resolved from configuration.
        $this->app->singleton(EmbeddingProvider::class, fn (): EmbeddingProvider => new OllamaEmbeddingProvider(
            baseUrl: config()->string('rag.embeddings.ollama.url'),
            model: config()->string('rag.embeddings.ollama.model'),
            dimensions: config()->integer('rag.embeddings.dimensions'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
