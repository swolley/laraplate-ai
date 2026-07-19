<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Contracts;

use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\Core\Models\User;

interface InAppAssistanceServiceInterface
{
    /**
     * @param  array<string, mixed>|null  $request_context
     */
    public function respond(
        Conversation $conversation,
        User $authenticated_user,
        string $user_input,
        ?array $request_context = null,
    ): Message;
}
