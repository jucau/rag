<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            // Fetch side (ADR-0020): raw materials and change detection.
            $table->unsignedBigInteger('revision_id')->nullable()->after('external_id');
            $table->longText('wikitext')->nullable()->after('content');
            $table->string('content_hash', 64)->nullable()->after('wikitext');

            // Index side: what the current chunks were built from.
            $table->timestamp('chunked_at')->nullable();
            $table->string('chunked_content_hash', 64)->nullable();
            $table->string('chunking_version', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn([
                'revision_id', 'wikitext', 'content_hash',
                'chunked_at', 'chunked_content_hash', 'chunking_version',
            ]);
        });
    }
};
