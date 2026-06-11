<?php

declare(strict_types=1);

namespace App\Rag\Corpus;

final readonly class WikipediaRevision
{
    public function __construct(
        public int $revisionId,
        public string $wikitext,
    ) {
    }
}
