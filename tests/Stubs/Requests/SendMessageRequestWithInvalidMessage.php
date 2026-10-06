<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Requests;

use Modules\AI\Http\Requests\SendMessageRequest;
use Override;

/**
 * A send-message request whose validated message is not a string, as if the rules had let it through.
 */
final class SendMessageRequestWithInvalidMessage extends SendMessageRequest
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function validated($key = null, $default = null): mixed
    {
        return ['message' => ['not a string']];
    }
}
