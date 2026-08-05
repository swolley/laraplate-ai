<?php

declare(strict_types=1);

use Modules\AI\Data\ModerationResult;
use Modules\AI\Enums\ModerationApprovalMode;
use Modules\AI\Enums\ModerationVerdict;
use Modules\AI\Jobs\ApproveModificationJob;
use Modules\AI\Services\ModerationService;
use Modules\CMS\Models\Comment;
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

    $this->system_user = User::factory()->create([
        'email' => 'ai-moderator@system.local',
        'username' => 'ai-moderator',
    ]);
    $this->system_user->assignRole(Role::findOrCreate('superadmin', 'web'));

    config([
        'ai.features.moderation.enabled' => true,
        'ai.features.moderation.system_user_id' => $this->system_user->id,
        'ai.features.moderation.approval_mode' => ModerationApprovalMode::Threshold->value,
        'ai.features.moderation.approve_confidence_threshold' => 0.85,
        'ai.features.moderation.reject_confidence_threshold' => 0.85,
        'ai.features.moderation.ai_participates_in_approvals' => true,
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
        'is_update' => false,
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

    (new ApproveModificationJob($modification))->handle($service, mockModerationRegistry($request));

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

    (new ApproveModificationJob($modification))->handle($service, mockModerationRegistry($request));

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

    (new ApproveModificationJob($modification))->handle($service, mockModerationRegistry($request));

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
