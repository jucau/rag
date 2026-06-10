<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            // Where the document comes from (e.g. "wikipedia") and its id
            // in that source (e.g. the Wikipedia page id). Together they
            // make ingestion idempotent: re-ingesting updates in place.
            $table->string('source');
            $table->string('external_id');
            $table->string('title');
            // Source URL, kept for CC BY-SA attribution and answer citations.
            $table->string('url');
            // Full raw text: allows re-chunking without re-fetching.
            $table->longText('content');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
