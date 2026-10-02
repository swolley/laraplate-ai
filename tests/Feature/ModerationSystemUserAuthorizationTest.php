<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Modules\AI\Services\ModerationSystemUser;

/**
 * Runs the real application seeding and checks the actor AI moderation votes as: the seeded
 * system user, resolved the way the job resolves it. Whether it may vote on a given module's
 * content is that module's permissions, checked in the application's integration tests.
 */
beforeEach(function (): void {
    Artisan::call('db:seed', ['--no-interaction' => true]);

    $this->system_user = app(ModerationSystemUser::class)->resolve();
});

it('resolves the seeded system user through the application user model, with its role', function (): void {
    expect($this->system_user)->toBeInstanceOf(user_class())
        ->and($this->system_user->roles()->pluck('name')->all())->toContain((string) config('permission.roles.system'));
});
