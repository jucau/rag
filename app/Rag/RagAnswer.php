<?php

declare(strict_types=1);

namespace App\Rag;

use App\Models\DocumentChunk;
use Illuminate\Support\Collection;

final readonly class RagAnswer
{
    /**
     * @param Collection<int, DocumentChunk> $chunks the retrieved context, for source attribution
     */
    public function __construct(
        public string $answer,
        public Collection $chunks,
        public string $provider,
    ) {
    }
}
