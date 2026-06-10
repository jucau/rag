<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('rag_queries', function (Blueprint $table): void {
            // Chunk ids became stale as soon as a document was re-ingested
            // (chunks are rebuilt). Denormalised [{title, url}] sources stay
            // readable for the life of the log row.
            $table->dropColumn('chunk_ids');
            $table->jsonb('sources')->default('[]')->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('rag_queries', function (Blueprint $table): void {
            $table->dropColumn('sources');
            $table->jsonb('chunk_ids')->default('[]')->after('provider');
        });
    }
};
