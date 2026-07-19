<?php

declare(strict_types=1);

namespace Modules\AI\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Modules\AI\Services\Assistance\AssistantControlPlaneData;

class SendMessageRequest extends FormRequest
{
    /**
     * @return array<string, list<string|Closure>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string'],
            'context' => [
                'nullable',
                'array',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_array($value) && AssistantControlPlaneData::containsForbiddenKey($value)) {
                        $fail('The context contains a prohibited assistant control field.');
                    }
                },
            ],
            'profile' => ['prohibited'],
            'user_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'permissions' => ['prohibited'],
            'roles' => ['prohibited'],
            'tools' => ['prohibited'],
            'system_message' => ['prohibited'],
            'system_prompt' => ['prohibited'],
        ];
    }

    // /**
    //  * Determine if the user is authorized to make this request.
    //  */
    // public function authorize(): bool
    // {
    //     return true;
    // }
}
