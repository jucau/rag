<?php

declare(strict_types=1);

use App\Http\Controllers\AskController;
use App\Rag\Chat\ChatProviderName;
use App\Rag\Chat\OllamaModel;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::get('/', function (): Response {
    // The OllamaModel enum is the single source of truth for the model
    // selector — the frontend never hardcodes model names.
    $models = array_map(fn (OllamaModel $model): array => [
        'provider' => ChatProviderName::Ollama->value,
        'model' => $model->value,
        'label' => $model->label(),
    ], OllamaModel::cases());

    $models[] = [
        'provider' => ChatProviderName::Claude->value,
        'model' => null,
        'label' => 'Claude Opus — API',
    ];

    return Inertia::render('Chat', ['models' => $models]);
})->name('home');

Route::post('/ask', AskController::class)->name('ask');
