<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Modules\AI\Jobs\ExecuteActionRequestJob;
use Modules\AI\Models\ActionRequest;
use Modules\AI\Models\Conversation;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Log::spy();
});

it('returns early when request is null', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);
    $actionRequest = ActionRequest::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'approved',
    ]);

    $actionRequest->delete();

    $job = new ExecuteActionRequestJob($actionRequest);
    $job->handle(createActionRequestService());

    expect(ActionRequest::query()->where('id', $actionRequest->id)->exists())->toBeFalse();
});

it('returns early when status is not approved or executing', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);
    $actionRequest = ActionRequest::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'pending',
    ]);

    $job = new ExecuteActionRequestJob($actionRequest);
    $job->handle(createActionRequestService());

    expect($actionRequest->fresh()->status)->toBe('pending');
});

it('updates status to executing runs handler and marks completed', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);
    $actionRequest = ActionRequest::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'approved',
    ]);

    $job = new ExecuteActionRequestJob($actionRequest);
    $job->handle(createActionRequestService());

    $actionRequest->refresh();
    expect($actionRequest->status)->toBe('completed')
        ->and($actionRequest->result)->toBe(['value' => 'result'])
        ->and($actionRequest->executed_at)->not->toBeNull();
});

it('marks failed on exception', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);
    $actionRequest = ActionRequest::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'tool_name' => 'failing_tool',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'approved',
    ]);

    $job = new ExecuteActionRequestJob($actionRequest);
    $job->handle(createActionRequestService(withFailingTool: true));

    $actionRequest->refresh();
    expect($actionRequest->status)->toBe('failed')
        ->and($actionRequest->error)->toBe('Tool failed')
        ->and($actionRequest->executed_at)->not->toBeNull();
});
