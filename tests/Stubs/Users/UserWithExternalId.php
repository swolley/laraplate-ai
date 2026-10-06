<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Users;

use Modules\Core\Models\User;
use Override;

/**
 * A user whose `id` is a string, as an identity provider outside the database would give it, and who has no role.
 */
final class UserWithExternalId extends User
{
    #[Override]
    public function hasRole($roles, ?string $guard = null): bool
    {
        return false;
    }

    #[Override]
    public function getAttribute($key): mixed
    {
        if ($key === 'id') {
            return 'external-user-id';
        }

        return parent::getAttribute($key);
    }
}
