<?php

declare(strict_types=1);

namespace App\Rag\Corpus;

final readonly class WikipediaPage
{
    public function __construct(
        public int $pageId,
        public string $title,
        public string $url,
        public string $content,
    ) {
    }
}
