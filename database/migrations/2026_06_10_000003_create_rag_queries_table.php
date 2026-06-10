<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // One row per rag:ask call: the question, what grounded the
        // answer, the answer, and where the time went (ADR-0011).
        Schema::create('rag_queries', function (Blueprint $table): void {
            $table->id();
            $table->text('question');
            $table->text('answer');
            $table->string('provider');
            $table->jsonb('chunk_ids');
            $table->unsignedInteger('embedding_ms');
            $table->unsignedInteger('retrieval_ms');
            $table->unsignedInteger('generation_ms');
            $table->unsignedInteger('total_ms');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_queries');
    }
};
