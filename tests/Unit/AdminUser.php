<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Unit;

use Modules\Core\Models\User;
use Override;

class AdminUser extends User
{
    #[Override]
    public function hasRole($roles, ?string $guard = null): bool
    {
        return true;
    }
}
