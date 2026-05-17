<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Modules\AI\Jobs\ApproveModificationJob;
use Modules\AI\Listeners\HandleModificationModerationListener;
use Modules\CMS\Models\Comment;
use Modules\CMS\Services\CommentModerationAdapter;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Events\ModificationRequiresModeration;
use Modules\Core\Models\Modification;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;
use Modules\Core\Services\ModerationAdapterRegistry;
use Modules\Core\Services\PerModelSettingResolver;

beforeEach(function (): void {
    app(PerModelSettingResolver::class)->flush();

    $registry = app(ModerationAdapterRegistry::class);
    $registry->register(app(CommentModerationAdapter::class));

    $this->system_user = User::factory()->create();
    config([
        'ai.features.moderation.enabled' => true,
        'ai.features.moderation.system_user_id' => $this->system_user->id,
        'ai.features.moderation.queue' => 'default',
    ]);

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'ai_moderation_' . (new Comment())->getTable(),
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'moderation',
        'description' => 'test',
    ]);

    app(PerModelSettingResolver::class)->flush();
});

it('dispatches approve modification job', function (): void {
    Queue::fake();

    $modification = Modification::query()->create([
        'modifiable_type' => Comment::class,
        'modifiable_id' => null,
        'modifier_id' => $this->system_user->id,
        'modifier_type' => User::class,
        'active' => true,
        'is_update' => false,
        'approvers_required' => 1,
        'disapprovers_required' => 1,
        'md5' => md5('listener'),
        'modifications' => ['body' => ['original' => null, 'modified' => 'Hi']],
    ]);

    app(HandleModificationModerationListener::class)->handle(new ModificationRequiresModeration($modification));

    Queue::assertPushed(ApproveModificationJob::class);
});

it('skips when feature is disabled', function (): void {
    Queue::fake();
    config(['ai.features.moderation.enabled' => false]);

    $modification = Modification::query()->create([
        'modifiable_type' => Comment::class,
        'modifiable_id' => null,
        'active' => true,
        'is_update' => false,
        'md5' => md5('off'),
        'modifications' => [],
    ]);

    app(HandleModificationModerationListener::class)->handle(new ModificationRequiresModeration($modification));

    Queue::assertNothingPushed();
});

it('skips when ai moderation is disabled for the modifiable model', function (): void {
    Queue::fake();

    Setting::query()
        ->where('name', 'ai_moderation_' . (new Comment())->getTable())
        ->update(['value' => false]);

    app(PerModelSettingResolver::class)->flush();

    $modification = Modification::query()->create([
        'modifiable_type' => Comment::class,
        'modifiable_id' => null,
        'modifier_id' => $this->system_user->id,
        'modifier_type' => User::class,
        'active' => true,
        'is_update' => false,
        'approvers_required' => 1,
        'disapprovers_required' => 1,
        'md5' => md5('no-ai-mod'),
        'modifications' => ['body' => ['original' => null, 'modified' => 'Hi']],
    ]);

    app(HandleModificationModerationListener::class)->handle(new ModificationRequiresModeration($modification));

    Queue::assertNothingPushed();
});

it('skips when no moderation adapter is registered for the modifiable type', function (): void {
    Queue::fake();

    $modification = Modification::query()->create([
        'modifiable_type' => User::class,
        'modifiable_id' => $this->system_user->id,
        'active' => true,
        'is_update' => false,
        'md5' => md5('user-mod'),
        'modifications' => ['name' => ['original' => 'a', 'modified' => 'b']],
    ]);

    app(HandleModificationModerationListener::class)->handle(new ModificationRequiresModeration($modification));

    Queue::assertNothingPushed();
});
