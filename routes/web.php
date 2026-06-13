<?php

declare(strict_types=1);

use App\Http\Controllers\AskController;
use App\Http\Controllers\AskStreamController;
use App\Http\Controllers\HistoryController;
use App\Rag\Chat\ChatProviderName;
use App\Rag\Chat\OllamaModel;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::get('/', function (): Response {
    // The OllamaModel enum is the single source of truth for the model
    // selector — the frontend never hardcodes model names. The configured
    // default model is moved first so the selector opens on it (the front
    // selects index 0); the stable sort keeps the enum order otherwise.
    $default = config()->string('rag.chat.ollama.model');
    $cases = OllamaModel::cases();
    usort($cases, fn (OllamaModel $a, OllamaModel $b): int => ($b->value === $default) <=> ($a->value === $default));

    $models = array_map(fn (OllamaModel $model): array => [
        'provider' => ChatProviderName::Ollama->value,
        'model' => $model->value,
        'label' => $model->label(),
    ], $cases);

    $models[] = [
        'provider' => ChatProviderName::Claude->value,
        'model' => null,
        'label' => 'Claude Opus — API',
    ];

    return Inertia::render('Chat', ['models' => $models]);
})->name('home');

Route::get('/history', HistoryController::class)->name('history');

Route::post('/ask', AskController::class)->name('ask');
Route::post('/ask-stream', AskStreamController::class)->name('ask.stream');
