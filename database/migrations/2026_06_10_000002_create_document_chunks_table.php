<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // The pgvector extension adds the "vector" column type and the
        // distance operators (<=> cosine, <-> L2, <#> inner product).
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        Schema::create('document_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            // Position of the chunk inside its document (0-based), so the
            // original reading order can be reconstructed.
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            // The embedding. The dimension (768) is structural: it matches
            // embeddinggemma and config rag.embeddings.dimensions (ADR-0006).
            $table->vector('embedding', dimensions: 768);
            $table->timestamps();

            $table->unique(['document_id', 'chunk_index']);
        });

        // HNSW index: approximate nearest-neighbour search, so similarity
        // queries stay fast as the corpus grows. vector_cosine_ops matches
        // the cosine distance operator (<=>) we use at query time.
        DB::statement('CREATE INDEX document_chunks_embedding_hnsw ON document_chunks USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
