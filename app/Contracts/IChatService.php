<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

use Modules\AI\Models\ActionRequest;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\Core\Models\User;

/**
 * Chat orchestration used by HTTP controllers (conversation lifecycle, messaging, streaming).
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

    /**
     * @param  array<string, mixed>|null  $context
     */
    public function sendMessage(
        Conversation $conversation,
        string $userMessage,
        ?array $context = null,
    ): Message;

    /**
     * @param  array<string, mixed>|null  $context
     * @param  callable(string): void  $on_chunk
     */
    public function sendMessageStream(
        Conversation $conversation,
        string $user_message,
        ?array $context,
        callable $on_chunk,
    ): Message;

    /**
     * @param  array<string, mixed>|null  $context
     *
     * @return array{message: Message, action_requests: array<int, ActionRequest>}
     */
    public function sendMessageWithTools(
        Conversation $conversation,
        string $user_message,
        ?array $context = null,
    ): array;
}
