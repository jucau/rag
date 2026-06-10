<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

/**
 * A passage of a document together with its embedding — the unit of
 * retrieval: similarity search returns chunks, not whole documents.
 *
 * @property int $id
 * @property int $document_id
 * @property int $chunk_index
 * @property string $content
 * @property Vector $embedding
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Document $document document_id is NOT NULL + cascade: always present
 */
class DocumentChunk extends Model
{
    use HasNeighbors;

    protected $guarded = [];

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'embedding' => Vector::class,
        ];
    }
}
