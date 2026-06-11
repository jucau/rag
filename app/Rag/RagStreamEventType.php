<?php

declare(strict_types=1);

namespace App\Rag;

/**
 * The SSE event names of a streamed answer (ADR-0017), in emission
 * order: sources right after retrieval, then one delta per generated
 * text fragment, then done with provider and timings.
 */
enum RagStreamEventType: string
{
    case Sources = 'sources';
    case Delta = 'delta';
    case Done = 'done';
}
