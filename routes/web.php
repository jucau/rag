<?php

declare(strict_types=1);

use App\Http\Controllers\AskController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

Route::get('/', fn (): Response => Inertia::render('Chat'))->name('home');

Route::post('/ask', AskController::class)->name('ask');
