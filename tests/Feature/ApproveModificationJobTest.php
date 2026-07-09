<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Modules\AI\Data\ModerationResult;
use Modules\AI\Enums\ModerationVerdict;
use Modules\AI\Jobs\ApproveModificationJob;
use Modules\AI\Services\ModerationService;
use Modules\CMS\Models\Comment;
use Modules\Core\Data\ModerationInput;
use Modules\Core\Data\ModerationRequest;
use Modules\Core\Events\ModificationPreProcessingCompleted;
use Modules\Core\Models\Approval;
use Modules\Core\Models\Disapproval;
use Modules\Core\Models\Modification;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Services\ModerationAdapterRegistry;

beforeEach(function (): void {
    $this->system_user = User::factory()->create();
    $this->system_user->assignRole(Role::findOrCreate('superadmin', 'web'));

    config([
        'ai.features.moderation.enabled' => true,
        'ai.features.moderation.system_user_id' => $this->system_user->id,
        'ai.features.moderation.ai_participates_in_approvals' => true,
        'ai.features.moderation.approval_mode' => 'threshold',
        'ai.features.moderation.approve_confidence_threshold' => 0.85,
        'ai.features.moderation.reject_confidence_threshold' => 0.85,
    ]);
});

function createCommentModerationModification(): Modification
{
    return Modification::query()->create([
        'modifiable_type' => Comment::class,
        'modifiable_id' => null,
        'modifier_id' => User::factory()->create()->id,
        'modifier_type' => User::class,
        'active' => true,
        'is_update' => false,
        'approvers_required' => 1,
        'disapprovers_required' => 1,
        'md5' => md5((string) microtime(true)),
        'modifications' => ['body' => ['original' => null, 'modified' => 'Moderation body']],
    ]);
}

function moderationRequestStub(): ModerationRequest
{
    return new ModerationRequest(
        input: new ModerationInput(
            subjectText: 'Moderation body',
            locale: 'en',
            contextSections: [],
            profile: 'test',
        ),
        systemPrompt: 'system',
        userPrompt: 'user',
    );
}

function bindModerationStack(ModerationResult $result, bool $supports = true): void
{
    $service = Mockery::mock(ModerationService::class);
    $service->shouldReceive('analyze')->andReturn($result);
    app()->instance(ModerationService::class, $service);

    $registry = Mockery::mock(ModerationAdapterRegistry::class);
    $registry->shouldReceive('supports')->andReturn($supports);
    $registry->shouldReceive('build')->andReturn(moderationRequestStub());
    app()->instance(ModerationAdapterRegistry::class, $registry);
}

it('skips inactive modifications', function (): void {
    Event::fake([ModificationPreProcessingCompleted::class]);

    $modification = createCommentModerationModification();
    $modification->update(['active' => false]);

    (new ApproveModificationJob($modification))->handle(
        app(ModerationService::class),
        app(ModerationAdapterRegistry::class),
    );

    Event::assertNotDispatched(ModificationPreProcessingCompleted::class);
});

it('skips when the system user is not configured', function (): void {
    Event::fake([ModificationPreProcessingCompleted::class]);
    config(['ai.features.moderation.system_user_id' => 0]);

    (new ApproveModificationJob(createCommentModerationModification()))->handle(
        app(ModerationService::class),
        app(ModerationAdapterRegistry::class),
    );

    Event::assertNotDispatched(ModificationPreProcessingCompleted::class);
});

it('dispatches preprocessing completed without voting when adapter is unsupported', function (): void {
    Event::fake([ModificationPreProcessingCompleted::class]);
    bindModerationStack(
        new ModerationResult(
            verdict: ModerationVerdict::Approve,
            confidence: 1.0,
            categories: [],
            reason: 'ok',
            safeToAutoApprove: true,
        ),
        supports: false,
    );

    (new ApproveModificationJob(createCommentModerationModification()))->handle(
        app(ModerationService::class),
        app(ModerationAdapterRegistry::class),
    );

    Event::assertDispatched(ModificationPreProcessingCompleted::class);
    expect(Approval::query()->count())->toBe(0);
});

it('skips voting when ai participation is disabled', function (): void {
    Event::fake([ModificationPreProcessingCompleted::class]);
    config(['ai.features.moderation.ai_participates_in_approvals' => false]);

    bindModerationStack(new ModerationResult(
        verdict: ModerationVerdict::Approve,
        confidence: 1.0,
        categories: [],
        reason: 'ok',
        safeToAutoApprove: true,
    ));

    (new ApproveModificationJob(createCommentModerationModification()))->handle(
        app(ModerationService::class),
        app(ModerationAdapterRegistry::class),
    );

    Event::assertDispatched(ModificationPreProcessingCompleted::class);
    expect(Approval::query()->count())->toBe(0);
});

it('routes threshold mode to uncertain when auto approval is unsafe', function (): void {
    bindModerationStack(new ModerationResult(
        verdict: ModerationVerdict::Approve,
        confidence: 0.99,
        categories: [],
        reason: 'Unsafe content',
        safeToAutoApprove: false,
    ));

    $modification = createCommentModerationModification();

    (new ApproveModificationJob($modification))->handle(
        app(ModerationService::class),
        app(ModerationAdapterRegistry::class),
    );

    $modification->refresh();
    expect($modification->disapprovers_required)->toBe(2);
    expect(Disapproval::query()->where('modification_id', $modification->id)->exists())->toBeTrue();
});

it('ensures a modifiable placeholder exists before casting votes', function (): void {
    $modification = createCommentModerationModification();
    $job = new ApproveModificationJob($modification);
    $method = new ReflectionMethod($job, 'ensureModifiableRelation');
    $method->invoke($job, $modification);

    expect($modification->modifiable)->toBeInstanceOf(Comment::class);
});

it('builds structured vote metadata for ai moderation', function (): void {
    $job = new ApproveModificationJob(createCommentModerationModification());
    $method = new ReflectionMethod($job, 'buildVoteMeta');
    $result = new ModerationResult(
        verdict: ModerationVerdict::Reject,
        confidence: 0.91,
        categories: ['spam'],
        reason: 'Spam',
        safeToAutoApprove: false,
    );

    $meta = $method->invoke($job, $result, 'auto_rejected', ['extra' => true]);

    expect($meta)->toMatchArray([
        'source' => 'ai',
        'status' => 'auto_rejected',
        'verdict' => ModerationVerdict::Reject->value,
        'confidence' => 0.91,
        'categories' => ['spam'],
        'reason' => 'Spam',
        'extra' => true,
    ]);
});

it('auto rejects high-confidence rejections in threshold mode', function (): void {
    bindModerationStack(new ModerationResult(
        verdict: ModerationVerdict::Reject,
        confidence: 0.95,
        categories: ['spam'],
        reason: 'Spam detected',
        safeToAutoApprove: false,
    ));

    $modification = createCommentModerationModification();

    (new ApproveModificationJob($modification))->handle(
        app(ModerationService::class),
        app(ModerationAdapterRegistry::class),
    );

    expect(Disapproval::query()->where('modification_id', $modification->id)->exists())->toBeTrue();
    expect(Disapproval::query()->first()?->meta['status'] ?? null)->toBe('auto_rejected');
});

it('applies uncertain fallback when confidence is below threshold', function (): void {
    bindModerationStack(new ModerationResult(
        verdict: ModerationVerdict::Approve,
        confidence: 0.5,
        categories: [],
        reason: 'Low confidence',
        safeToAutoApprove: true,
    ));

    $modification = createCommentModerationModification();

    (new ApproveModificationJob($modification))->handle(
        app(ModerationService::class),
        app(ModerationAdapterRegistry::class),
    );

    $modification->refresh();
    expect($modification->disapprovers_required)->toBe(2);
    expect(Disapproval::query()->where('modification_id', $modification->id)->exists())->toBeTrue();
    expect(Disapproval::query()->first()?->meta['status'] ?? null)->toBe('requires_human_review');
});

it('casts the first ai vote in dual approval mode', function (): void {
    config(['ai.features.moderation.approval_mode' => 'dual']);

    bindModerationStack(new ModerationResult(
        verdict: ModerationVerdict::Approve,
        confidence: 0.95,
        categories: [],
        reason: 'Preliminary approve',
        safeToAutoApprove: true,
    ));

    $modification = createCommentModerationModification();

    (new ApproveModificationJob($modification))->handle(
        app(ModerationService::class),
        app(ModerationAdapterRegistry::class),
    );

    $modification->refresh();
    expect($modification->approvers_required)->toBe(2)
        ->and($modification->disapprovers_required)->toBe(2);
    expect(Approval::query()->where('modification_id', $modification->id)->exists())->toBeTrue();
    expect(Approval::query()->first()?->meta['requires_human_approval'] ?? null)->toBeTrue();
});

it('casts preliminary disapproval in dual mode when ai rejects', function (): void {
    config(['ai.features.moderation.approval_mode' => 'dual']);

    bindModerationStack(new ModerationResult(
        verdict: ModerationVerdict::Reject,
        confidence: 0.95,
        categories: ['toxic'],
        reason: 'Toxic content',
        safeToAutoApprove: false,
    ));

    $modification = createCommentModerationModification();

    (new ApproveModificationJob($modification))->handle(
        app(ModerationService::class),
        app(ModerationAdapterRegistry::class),
    );

    expect(Disapproval::query()->where('modification_id', $modification->id)->exists())->toBeTrue();
    expect(Disapproval::query()->first()?->meta['preliminary_disapproval'] ?? null)->toBeTrue();
});

it('falls back to human review when moderation analysis fails', function (): void {
    $service = Mockery::mock(ModerationService::class);
    $service->shouldReceive('analyze')->andThrow(new RuntimeException('provider down'));
    app()->instance(ModerationService::class, $service);

    $registry = Mockery::mock(ModerationAdapterRegistry::class);
    $registry->shouldReceive('supports')->andReturn(true);
    $registry->shouldReceive('build')->andReturn(moderationRequestStub());
    app()->instance(ModerationAdapterRegistry::class, $registry);

    $modification = createCommentModerationModification();

    (new ApproveModificationJob($modification))->handle(
        app(ModerationService::class),
        app(ModerationAdapterRegistry::class),
    );

    expect(Disapproval::query()->where('modification_id', $modification->id)->exists())->toBeTrue();
    expect($modification->fresh()?->disapprovers_required)->toBe(2);
});

it('always dispatches preprocessing completed event', function (): void {
    Event::fake([ModificationPreProcessingCompleted::class]);

    bindModerationStack(new ModerationResult(
        verdict: ModerationVerdict::Approve,
        confidence: 0.95,
        categories: [],
        reason: 'ok',
        safeToAutoApprove: true,
    ));

    $modification = createCommentModerationModification();

    (new ApproveModificationJob($modification))->handle(
        app(ModerationService::class),
        app(ModerationAdapterRegistry::class),
    );

    Event::assertDispatched(
        ModificationPreProcessingCompleted::class,
        fn (ModificationPreProcessingCompleted $event): bool => $event->modification->is($modification)
            && $event->processing_type === 'ai_approval',
    );
});
