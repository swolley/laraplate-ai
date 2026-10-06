<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Users;

use Modules\Core\Models\User;
use Override;

/**
 * A user whose key is whatever the test says, for the code that has to cope with a key that is not an integer.
 */
final class UserWithFixedKey extends User
{
    private mixed $fixedKey = null;

    public static function keyed(mixed $key): self
    {
        $user = new self;
        $user->fixedKey = $key;

        return $user;
    }

    #[Override]
    public function getKey(): mixed
    {
        return $this->fixedKey;
    }
}
