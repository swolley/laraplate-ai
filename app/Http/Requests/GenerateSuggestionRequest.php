<?php

declare(strict_types=1);

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request for generating a contextual suggestion.
 */
class GenerateSuggestionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'context' => ['required', 'array'],
            'context.page' => ['sometimes', 'string', 'max:255'],
            'context.action' => ['sometimes', 'string', 'max:255'],
            'context.data' => ['sometimes', 'array'],
        ];
    }
}
