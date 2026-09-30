<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Modules\Core\Models\User;

/**
 * The actor AI moderation votes as: the platform system user, the one Core seeds under
 * `permission.users.system` (env `SYSTEM_USER`). Votes are a system-level operation, so no
 * separate moderator account is configured.
 *
 * The lookup goes through the application's user model ({@see user_class()}): roles and permissions
 * are stored against that class, so the base Core model would see the same row with none of them.
 */
final readonly class ModerationSystemUser
{
    /**
     * Null when the username is not configured or no user carries it.
     */
    public function resolve(): ?User
    {
        $username = config('permission.users.system');

        if (! is_string($username) || $username === '') {
            return null;
        }

        $user_class = user_class();

        return $user_class::query()->where('username', $username)->first();
    }
}
