<?php

declare(strict_types=1);

namespace Modules\AI\Observers;

use Modules\AI\Models\ContextualSuggestion;
use Modules\AI\Models\Conversation;
use Modules\Core\Models\User;

/**
 * A user is soft deleted by default, and a soft delete never reaches the foreign keys that cascade
 * a hard delete. What the assistant keeps about the user is removed here: the suggestions, soft
 * deleted ones too, and the conversations with all that they hold. The approval requests of the
 * action flow stay: they record who asked for what and who decided.
 */
final class PurgeAssistantDataOfDeletedUserObserver
{
    public function deleted(User $user): void
    {
        ContextualSuggestion::query()->withTrashed()->where('user_id', $user->getKey())->forceDelete();

        Conversation::query()->withTrashed()->where('user_id', $user->getKey())->get()->each(
            static function (Conversation $conversation): void {
                // A conversation deleted before the purge existed still holds its content.
                $conversation->trashed() ? $conversation->purgeContent() : $conversation->delete();
            },
        );
    }
}
