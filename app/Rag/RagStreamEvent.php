<?php

declare(strict_types=1);

namespace App\Rag;

final readonly class RagStreamEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public RagStreamEventType $type,
        public array $data,
    ) {
    }
}
