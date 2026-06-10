<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A source document (e.g. a Wikipedia article), kept in full so it can be
 * re-chunked without re-fetching. Searchable content lives in its chunks.
 *
 * @property int $id
 * @property string $source
 * @property string $external_id
 * @property string $title
 * @property string $url
 * @property string $content
 * @property CarbonImmutable $fetched_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Document extends Model
{
    #[\Override]
    protected $guarded = [];

    /**
     * @return HasMany<DocumentChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'fetched_at' => 'immutable_datetime',
        ];
    }
}
