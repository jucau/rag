<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One rag:ask call: question, grounding chunks, answer and per-phase
 * timings (ADR-0011). Doubles as an evaluation dataset later on.
 *
 * @property int $id
 * @property string $question
 * @property string $answer
 * @property string $provider
 * @property list<array{position: int, chunk_id: int, title: string, url: string, distance: float}> $sources
 * @property int $embedding_ms
 * @property int $retrieval_ms
 * @property int $generation_ms
 * @property int $total_ms
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class RagQuery extends Model
{
    #[\Override]
    protected $guarded = [];

    #[\Override]
    protected function casts(): array
    {
        return [
            'sources' => 'array',
        ];
    }
}
