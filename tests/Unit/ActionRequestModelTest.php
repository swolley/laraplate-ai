<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\ActionRequest;
use Modules\AI\Models\Conversation;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

it('has fillable attributes', function (): void {
    $request = new ActionRequest;

    expect($request->getFillable())->toBe([
        'conversation_id',
        'user_id',
        'tool_name',
        'tool_args',
        'risk_level',
        'status',
        'modification_id',
        'result',
        'error',
        'executed_at',
    ]);
});

it('casts tool_args result and executed_at correctly', function (): void {
    $request = new ActionRequest;

    expect($request->getCasts())->toMatchArray([
        'tool_args' => 'array',
        'result' => 'array',
        'executed_at' => 'datetime',
    ]);
});

it('requires approval when risk_level is high', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);
    $request = ActionRequest::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'high',
        'status' => 'pending_admin_approval',
    ]);

    expect($request->requiresApproval())->toBeTrue();
});

it('does not require approval when risk_level is not high', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);
    $request = ActionRequest::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'pending',
    ]);

    expect($request->requiresApproval())->toBeFalse();
});

it('requires user confirmation when risk_level is medium', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);
    $request = ActionRequest::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'medium',
        'status' => 'pending_user_confirmation',
    ]);

    expect($request->requiresUserConfirmation())->toBeTrue();
});

it('does not require user confirmation when risk_level is not medium', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);
    $request = ActionRequest::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'pending',
    ]);

    expect($request->requiresUserConfirmation())->toBeFalse();
});

it('belongs to conversation', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);
    $request = ActionRequest::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'pending',
    ]);

    expect($request->conversation)->toBeInstanceOf(Conversation::class)
        ->and($request->conversation->id)->toBe($conversation->id);
});

it('belongs to user', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);
    $request = ActionRequest::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'pending',
    ]);

    expect($request->user)->toBeInstanceOf(User::class)
        ->and($request->user->id)->toBe($user->id);
});

it('filters by pendingUserConfirmation scope', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);
    ActionRequest::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'pending_user_confirmation',
    ]);
    ActionRequest::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'tool_name' => 'test2',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'pending',
    ]);

    expect(ActionRequest::pendingUserConfirmation()->count())->toBe(1);
});

it('filters by pendingAdminApproval scope', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);
    ActionRequest::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'high',
        'status' => 'pending_admin_approval',
    ]);
    ActionRequest::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $user->id,
        'tool_name' => 'test2',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'pending',
    ]);

    expect(ActionRequest::pendingAdminApproval()->count())->toBe(1);
});

it('filters by forUser scope', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $conversation1 = Conversation::query()->create(['user_id' => $user1->id]);
    $conversation2 = Conversation::query()->create(['user_id' => $user2->id]);
    ActionRequest::query()->create([
        'conversation_id' => $conversation1->id,
        'user_id' => $user1->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'pending',
    ]);
    ActionRequest::query()->create([
        'conversation_id' => $conversation2->id,
        'user_id' => $user2->id,
        'tool_name' => 'test2',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'pending',
    ]);

    expect(ActionRequest::forUser($user1->id)->count())->toBe(1)
        ->and(ActionRequest::forUser($user2->id)->count())->toBe(1);
});
