<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Unit;

use Modules\Core\Models\User;

class AdminUser extends User
{
    public function hasRole(array|string $roles): bool
    {
        return true;
    }
}
