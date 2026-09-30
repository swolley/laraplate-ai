<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Modules\AI\Services\ModerationSystemUser;
use Modules\CMS\Models\Comment;
use Modules\Core\Approvals\Operation;
use Modules\Core\Models\Modification;
use Modules\Core\Models\User;

/**
 * Runs the real application seeding and checks the actor AI moderation votes as: the seeded
 * system user, resolved the way the job resolves it.
 */
beforeEach(function (): void {
    Artisan::call('db:seed', ['--no-interaction' => true]);

    $this->system_user = app(ModerationSystemUser::class)->resolve();

    $this->modification = Modification::query()->create([
        'modifiable_type' => Comment::class,
        'modifiable_id' => null,
        'modifier_id' => User::factory()->create()->id,
        'modifier_type' => User::class,
        'active' => true,
        'operation' => Operation::Create,
        'approvers_required' => 1,
        'disapprovers_required' => 1,
        'md5' => md5('system-user-authorization'),
        'modifications' => ['body' => ['original' => null, 'modified' => 'Hi']],
    ]);
});

it('resolves the seeded system user through the application user model, with its role', function (): void {
    expect($this->system_user)->toBeInstanceOf(user_class())
        ->and($this->system_user->roles()->pluck('name')->all())->toContain((string) config('permission.roles.system'));
});

it('lets the seeded system user approve a comment modification', function (): void {
    expect($this->system_user->isAuthorizedToCastApprovalVote($this->modification, true))->toBeTrue();
});

it('lets the seeded system user disapprove a comment modification', function (): void {
    expect($this->system_user->isAuthorizedToCastApprovalVote($this->modification, false))->toBeTrue();
});
