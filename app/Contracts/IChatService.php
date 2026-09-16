<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

use Modules\AI\Models\Conversation;
use Modules\Core\Models\User;

/**
 * Conversation lifecycle used by HTTP controllers.
 *
 * Messaging is not part of this contract: every HTTP message endpoint goes
 * through InAppAssistanceService, which applies policy, guardrails and scope.
 */
interface IChatService
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function createConversation(
        User $user,
        ?string $title = null,
        ?string $systemMessage = null,
        ?array $metadata = null,
    ): Conversation;
}
