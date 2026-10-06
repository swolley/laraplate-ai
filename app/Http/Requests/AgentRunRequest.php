<?php

declare(strict_types=1);

namespace Modules\AI\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Response;
use Override;

/**
 * The public input of a run: the conversation, the message and the context a client may send. It is
 * the same input, with the same control-plane check, as a message sent to the base transport; the
 * only addition is the thread. Whatever else a client sends is not read.
 */
final class AgentRunRequest extends SendMessageRequest
{
    /**
     * @return array<string, list<string|Closure>>
     */
    #[Override]
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'threadId' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * A client that waits for a stream sends `Accept: text/event-stream`, which a redirect to a form
     * page cannot serve: an input that is refused is always answered as JSON.
     */
    #[Override]
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
