<?php

declare(strict_types=1);

use Modules\AI\Data\ModerationResult;
use Modules\AI\Enums\ModerationApprovalMode;
use Modules\AI\Enums\ModerationVerdict;
use Modules\AI\Jobs\ApproveModificationJob;
use Modules\AI\Services\ModerationService;
use Modules\AI\Services\ModerationSystemUser;
use Modules\CMS\Models\Comment;
use Modules\Core\Approvals\Operation;
use Modules\Core\Data\ModerationInput;
use Modules\Core\Data\ModerationRequest;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\Approval;
use Modules\Core\Models\Disapproval;
use Modules\Core\Models\Modification;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Services\ModerationAdapterRegistry;

beforeEach(function (): void {
    LocaleContext::set('en');
    $this->content = createMinimalTestContentForComments();

    $user_class = user_class();
    $this->system_user = $user_class::factory()->create([
        'email' => 'ai-moderator@system.local',
        'username' => 'ai-moderator',
    ]);
    $this->system_user->assignRole(Role::findOrCreate('superadmin', 'web'));

    config([
        'ai.features.moderation.enabled' => true,
        'permission.users.system' => $this->system_user->username,
        'ai.features.moderation.approval_mode' => ModerationApprovalMode::Threshold->value,
        'ai.features.moderation.threshold.approve' => 0.85,
        'ai.features.moderation.threshold.reject' => 0.85,
        'ai.features.moderation.votes' => true,
    ]);
});

function createCommentModification(array $changes = []): Modification
{
    $content_id = test()->content->id;
    $author = User::factory()->create();

    $defaults = [
        'content_id' => ['original' => null, 'modified' => $content_id],
        'user_id' => ['original' => null, 'modified' => $author->id],
        'body' => ['original' => null, 'modified' => 'Test comment body'],
        'locale' => ['original' => null, 'modified' => 'en'],
    ];

    return Modification::query()->create([
        'modifiable_type' => Comment::class,
        'modifiable_id' => null,
        'modifier_id' => $author->id,
        'modifier_type' => User::class,
        'active' => true,
        'operation' => Operation::Create,
        'approvers_required' => 1,
        'disapprovers_required' => 1,
        'md5' => md5(json_encode($changes ?: $defaults)),
        'modifications' => array_merge($defaults, $changes),
    ]);
}

function mockModerationRegistry(ModerationRequest $request): ModerationAdapterRegistry
{
    $registry = Mockery::mock(ModerationAdapterRegistry::class);
    $registry->shouldReceive('supports')->andReturn(true);
    $registry->shouldReceive('build')->andReturn($request);

    return $registry;
}

function testModerationRequest(string $body = 'Test comment body'): ModerationRequest
{
    $input = new ModerationInput(
        subjectText: $body,
        locale: 'en',
        contextSections: ['Article title' => 'Title'],
        profile: 'cms.comment',
    );

    return new ModerationRequest(
        input: $input,
        systemPrompt: 'Moderate.',
        userPrompt: $body,
    );
}

it('casts preliminary disapprove when uncertain and stores meta on disapproval', function (): void {
    $modification = createCommentModification();

    $result = new ModerationResult(
        verdict: ModerationVerdict::Uncertain,
        confidence: 0.4,
        categories: ['off_topic'],
        reason: 'Cannot determine safety.',
        safeToAutoApprove: false,
    );

    $request = testModerationRequest();

    $service = Mockery::mock(ModerationService::class);
    $service->shouldReceive('analyze')->once()->andReturn($result);

    (new ApproveModificationJob($modification))->handle($service, mockModerationRegistry($request), app(ModerationSystemUser::class));

    $modification->refresh();
    $disapproval = Disapproval::query()->where('modification_id', $modification->id)->first();

    expect($modification->disapprovers_required)->toBe(2)
        ->and($modification->disapprovals()->count())->toBe(1)
        ->and($disapproval?->meta)->toMatchArray([
            'source' => 'ai',
            'status' => 'requires_human_review',
            'verdict' => 'uncertain',
            'confidence' => 0.4,
            'requires_human_approval' => true,
            'preliminary_disapproval' => true,
        ]);
});

it('auto approves when confidence is high and stores meta on approval', function (): void {
    $modification = createCommentModification();

    $result = new ModerationResult(
        verdict: ModerationVerdict::Approve,
        confidence: 0.99,
        categories: [],
        reason: 'Clearly acceptable.',
        safeToAutoApprove: true,
    );

    $request = testModerationRequest('Test');

    $service = Mockery::mock(ModerationService::class);
    $service->shouldReceive('analyze')->once()->andReturn($result);

    (new ApproveModificationJob($modification))->handle($service, mockModerationRegistry($request), app(ModerationSystemUser::class));

    $approval = Approval::query()->where('modification_id', $modification->id)->first();

    expect(Comment::withoutGlobalScopes()->count())->toBe(1)
        ->and($approval?->meta)->toMatchArray([
            'source' => 'ai',
            'status' => 'auto_approved',
            'verdict' => 'approve',
            'confidence' => 0.99,
        ]);
});

it('auto rejects when verdict is reject with high confidence and stores meta on disapproval', function (): void {
    $modification = createCommentModification([
        'body' => ['original' => null, 'modified' => 'Spam spam spam'],
    ]);

    $result = new ModerationResult(
        verdict: ModerationVerdict::Reject,
        confidence: 0.99,
        categories: ['spam'],
        reason: 'Spam detected.',
        safeToAutoApprove: false,
    );

    $request = testModerationRequest('Spam');

    $service = Mockery::mock(ModerationService::class);
    $service->shouldReceive('analyze')->once()->andReturn($result);

    (new ApproveModificationJob($modification))->handle($service, mockModerationRegistry($request), app(ModerationSystemUser::class));

    $disapproval = Disapproval::query()->where('modification_id', $modification->id)->first();

    expect(Comment::withoutGlobalScopes()->count())->toBe(0)
        ->and($disapproval?->meta)->toMatchArray([
            'source' => 'ai',
            'status' => 'auto_rejected',
            'verdict' => 'reject',
            'confidence' => 0.99,
            'categories' => ['spam'],
        ]);
});

it('never publishes a comment a human rejects after the AI preliminary disapproval', function (): void {
    $modification = createCommentModification();
    $service = Mockery::mock(ModerationService::class);
    $service->shouldReceive('analyze')->once()->andReturn(new ModerationResult(
        verdict: ModerationVerdict::Uncertain,
        confidence: 0.4,
        categories: ['off_topic'],
        reason: 'Cannot determine safety.',
        safeToAutoApprove: false,
    ));
    (new ApproveModificationJob($modification))->handle($service, mockModerationRegistry(testModerationRequest()), app(ModerationSystemUser::class));

    $modification->refresh();
    expect($modification->disapprovers_required)->toBe(2)
        ->and($modification->active)->toBeTrue();

    $moderator = User::factory()->create();
    $moderator->assignRole(Role::findOrCreate('superadmin', 'web'));
    resolve(Modules\Core\Services\ModificationVoteService::class)->cast($moderator, $modification->fresh(), false, 'Off topic');

    expect($modification->fresh()->active)->toBeFalse()
        ->and($modification->fresh()->disapprovals()->count())->toBe(2)
        ->and(Comment::query()->withoutGlobalScopes()->count())->toBe(0);
});
