<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
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
        'permission.users.system' => 'system',
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

it('skips when the system user is not configured', function (): void {
    Queue::fake();
    config(['permission.users.system' => '']);

    $modification = Modification::query()->create([
        'modifiable_type' => Comment::class,
        'modifiable_id' => null,
        'modifier_id' => $this->system_user->id,
        'modifier_type' => User::class,
        'active' => true,
        'is_update' => false,
        'approvers_required' => 1,
        'disapprovers_required' => 1,
        'md5' => md5('no-system-user'),
        'modifications' => ['body' => ['original' => null, 'modified' => 'Hi']],
    ]);

    app(HandleModificationModerationListener::class)->handle(new ModificationRequiresModeration($modification));

    Queue::assertNothingPushed();
});

it('skips inactive modifications', function (): void {
    Queue::fake();

    $modification = Modification::query()->create([
        'modifiable_type' => Comment::class,
        'modifiable_id' => null,
        'modifier_id' => $this->system_user->id,
        'modifier_type' => User::class,
        'active' => false,
        'is_update' => false,
        'approvers_required' => 1,
        'disapprovers_required' => 1,
        'md5' => md5('inactive'),
        'modifications' => ['body' => ['original' => null, 'modified' => 'Hi']],
    ]);

    app(HandleModificationModerationListener::class)->handle(new ModificationRequiresModeration($modification));

    Queue::assertNothingPushed();
});

it('caches async moderation events for later correlation', function (): void {
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
        'md5' => md5('cache-async'),
        'modifications' => ['body' => ['original' => null, 'modified' => 'Hi']],
    ]);

    $event = new ModificationRequiresModeration($modification);

    Cache::shouldReceive('put')
        ->once()
        ->withArgs(function (string $key, mixed $value, DateTimeInterface $ttl) use ($modification, $event): bool {
            return $key === 'modification_moderation:' . $modification->id
                && $value === $event;
        });

    app(HandleModificationModerationListener::class)->handle($event);

    Queue::assertPushed(ApproveModificationJob::class);
});

it('does not cache sync moderation events', function (): void {
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
        'md5' => md5('cache-sync'),
        'modifications' => ['body' => ['original' => null, 'modified' => 'Hi']],
    ]);

    Cache::shouldReceive('put')->never();

    app(HandleModificationModerationListener::class)->handle(new ModificationRequiresModeration($modification, sync: true));

    Queue::assertPushed(ApproveModificationJob::class);
});

it('evaluates moderation support from a loaded modifiable model', function (): void {
    Queue::fake();

    $content = createMinimalTestContentForComments();
    $comment = Comment::factory()->approved()->create([
        'content_id' => $content->id,
        'user_id' => $this->system_user->id,
    ]);

    $modification = Modification::query()->create([
        'modifiable_type' => Comment::class,
        'modifiable_id' => $comment->id,
        'modifier_id' => $this->system_user->id,
        'modifier_type' => User::class,
        'active' => true,
        'is_update' => false,
        'approvers_required' => 1,
        'disapprovers_required' => 1,
        'md5' => md5('loaded-modifiable'),
        'modifications' => ['body' => ['original' => null, 'modified' => 'Hi']],
    ]);
    $modification->setRelation('modifiable', $comment);

    app(HandleModificationModerationListener::class)->handle(new ModificationRequiresModeration($modification));

    Queue::assertPushed(ApproveModificationJob::class);
});

it('skips when the modifiable class does not exist', function (): void {
    Queue::fake();

    $modification = Modification::query()->create([
        'modifiable_type' => 'App\\Models\\DoesNotExist',
        'modifiable_id' => null,
        'modifier_id' => $this->system_user->id,
        'modifier_type' => User::class,
        'active' => true,
        'is_update' => false,
        'approvers_required' => 1,
        'disapprovers_required' => 1,
        'md5' => md5('missing-class'),
        'modifications' => ['body' => ['original' => null, 'modified' => 'Hi']],
    ]);

    app(HandleModificationModerationListener::class)->handle(new ModificationRequiresModeration($modification));

    Queue::assertNothingPushed();
});

it('skips when the modifiable class is not an eloquent model', function (): void {
    $listener = app(HandleModificationModerationListener::class);
    $method = new ReflectionMethod($listener, 'modifiableSupportsAiModeration');

    $modification = Mockery::mock(Modification::class);
    $modification->shouldReceive('getAttribute')->andReturnUsing(
        static fn (string $key): mixed => match ($key) {
            'modifiable' => null,
            'modifiable_type' => Illuminate\Support\Collection::class,
            default => null,
        },
    );

    expect($method->invoke($listener, $modification))->toBeFalse();
});

it('skips when the modifiable model does not use approval workflows', function (): void {
    Queue::fake();

    $registry = Mockery::mock(ModerationAdapterRegistry::class);
    $registry->shouldReceive('supports')->andReturn(true);
    app()->instance(ModerationAdapterRegistry::class, $registry);

    $modification = Modification::query()->create([
        'modifiable_type' => User::class,
        'modifiable_id' => $this->system_user->id,
        'modifier_id' => $this->system_user->id,
        'modifier_type' => User::class,
        'active' => true,
        'is_update' => false,
        'approvers_required' => 1,
        'disapprovers_required' => 1,
        'md5' => md5('no-approvals'),
        'modifications' => ['name' => ['original' => 'a', 'modified' => 'b']],
    ]);

    app(HandleModificationModerationListener::class)->handle(new ModificationRequiresModeration($modification));

    Queue::assertNothingPushed();

    app()->forgetInstance(ModerationAdapterRegistry::class);
    app(ModerationAdapterRegistry::class)->register(app(CommentModerationAdapter::class));
});

it('does not cache events when the modification has no cacheable key', function (): void {
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
        'md5' => md5('no-cache-key'),
        'modifications' => ['body' => ['original' => null, 'modified' => 'Hi']],
    ]);

    $modification = Mockery::mock($modification)->makePartial();
    $modification->shouldReceive('getKey')->andReturn(null);

    Cache::spy();

    app(HandleModificationModerationListener::class)->handle(new ModificationRequiresModeration($modification));

    Cache::shouldNotHaveReceived('put');
    Queue::assertPushed(ApproveModificationJob::class);
});
