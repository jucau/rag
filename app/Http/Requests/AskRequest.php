<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rag\Chat\ChatProviderName;
use App\Rag\Chat\OllamaModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AskRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'max:1000'],
            // The enums are the whitelists: the browser can never request
            // a provider or model outside of them.
            'provider' => ['nullable', 'string', Rule::enum(ChatProviderName::class)],
            'model' => ['nullable', 'string', Rule::enum(OllamaModel::class)],
        ];
    }
}
