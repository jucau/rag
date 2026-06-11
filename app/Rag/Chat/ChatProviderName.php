<?php

declare(strict_types=1);

namespace App\Rag\Chat;

enum ChatProviderName: string
{
    case Ollama = 'ollama';
    case Claude = 'claude';
}
