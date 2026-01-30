<?php

declare(strict_types=1);

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ApproveActionRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
