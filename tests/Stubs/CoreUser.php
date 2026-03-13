<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use Override;

if (class_exists(User::class, false)) {
    return;
}

class User extends \Illuminate\Foundation\Auth\User
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    #[Override]
    protected $table = 'users';

    #[Override]
    protected $fillable = ['name', 'email', 'password'];

    public function hasRole(array|string $roles): bool
    {
        return false;
    }

    protected static function newFactory(): \Modules\AI\Tests\Stubs\UserFactory
    {
        return \Modules\AI\Tests\Stubs\UserFactory::new();
    }
}
