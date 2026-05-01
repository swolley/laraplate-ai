<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Unit;

use Override;
use Modules\Core\Models\User;

class AdminUser extends User
{
    #[Override]
    public function hasRole($roles, ?string $guard = null): bool
    {
        return true;
    }
}
