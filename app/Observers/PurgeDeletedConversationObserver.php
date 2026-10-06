<?php

declare(strict_types=1);

namespace Modules\AI\Observers;

use Modules\AI\Models\Conversation;

/**
 * A conversation is soft deleted by default, which leaves in place everything it holds: its
 * messages with their citations and proposals, its summaries, and the title, summary and system
 * message on its own row. That is the user's data, removed with the conversation.
 */
final class PurgeDeletedConversationObserver
{
    public function deleted(Conversation $conversation): void
    {
        $conversation->purgeContent();
    }
}
