<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A source document (e.g. a Wikipedia article), kept raw (plain-text
 * extract + wikitext) so it can be re-indexed without re-fetching
 * (ADR-0020). Searchable content lives in its chunks.
 *
 * @property int $id
 * @property string $source
 * @property string $external_id
 * @property int|null $revision_id Wikipedia lastrevid at fetch time
 * @property string $title
 * @property string $url
 * @property string $content plain-text extract (prose)
 * @property string|null $wikitext raw wikitext (infobox/tables source)
 * @property string|null $content_hash sha256 over content + wikitext
 * @property CarbonImmutable $fetched_at
 * @property CarbonImmutable|null $chunked_at
 * @property string|null $chunked_content_hash content_hash when last indexed
 * @property string|null $chunking_version strategy version when last indexed
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

    /**
     * Why this document needs (re)indexing — null when its chunks are
     * up to date with both the content and the chunking strategy.
     */
    public function indexingReason(string $currentVersion): ?string
    {
        if ($this->chunked_at === null) {
            return 'never indexed';
        }

        if ($this->chunked_content_hash !== $this->content_hash) {
            return 'content changed since last indexing';
        }

        if ($this->chunking_version !== $currentVersion) {
            return sprintf('chunking strategy changed (%s → %s)', $this->chunking_version ?? '?', $currentVersion);
        }

        return null;
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'fetched_at' => 'immutable_datetime',
            'chunked_at' => 'immutable_datetime',
        ];
    }
}
