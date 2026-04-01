<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\AI\Jobs\ExecuteActionRequestJob;
use Modules\AI\Models\ActionRequest;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\ActionRequestService;
use Modules\AI\Services\Tools\RiskClassifier;
use Modules\AI\Services\Tools\ToolRegistry;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
});

it('createRequest creates an ActionRequest with low risk and dispatches job', function (): void {
    $toolRegistry = new ToolRegistry;
    $toolRegistry->register('test', static fn (): string => 'result', 'test', [], 'low');

    $riskClassifier = new RiskClassifier(['test' => ['risk_level' => 'low']]);

    $service = new ActionRequestService($toolRegistry, $riskClassifier);
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id, 'title' => 'Test']);

    $request = $service->createRequest($user, 'test', [], $conversation);

    expect($request)->toBeInstanceOf(ActionRequest::class)
        ->and($request->status)->toBe('approved')
        ->and($request->risk_level)->toBe('low')
        ->and($request->tool_name)->toBe('test');

    Queue::assertPushed(ExecuteActionRequestJob::class);
});

it('createRequest creates request with medium risk and pending_user_confirmation status', function (): void {
    $toolRegistry = new ToolRegistry;
    $toolRegistry->register('test', static fn (): string => 'result', 'test', [], 'medium');

    $riskClassifier = new RiskClassifier(['test' => ['risk_level' => 'medium']]);

    $service = new ActionRequestService($toolRegistry, $riskClassifier);
    $user = User::factory()->create();

    $request = $service->createRequest($user, 'test', []);

    expect($request->status)->toBe('pending_user_confirmation')
        ->and($request->risk_level)->toBe('medium');

    Queue::assertNotPushed(ExecuteActionRequestJob::class);
});

it('createRequest creates request with high risk and pending_admin_approval status', function (): void {
    $toolRegistry = new ToolRegistry;
    $toolRegistry->register('test', static fn (): string => 'result', 'test', [], 'high');

    $riskClassifier = new RiskClassifier(['test' => ['risk_level' => 'high']]);

    $service = new ActionRequestService($toolRegistry, $riskClassifier);
    $user = User::factory()->create();

    $request = $service->createRequest($user, 'test', []);

    expect($request->status)->toBe('pending_admin_approval')
        ->and($request->risk_level)->toBe('high');

    Queue::assertNotPushed(ExecuteActionRequestJob::class);
});

it('confirmRequest updates status to approved and dispatches job', function (): void {
    $toolRegistry = new ToolRegistry;
    $toolRegistry->register('test', static fn (): string => 'result', 'test', [], 'low');
    $riskClassifier = new RiskClassifier;

    $service = new ActionRequestService($toolRegistry, $riskClassifier);
    $user = User::factory()->create();

    $request = ActionRequest::query()->create([
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'medium',
        'status' => 'pending_user_confirmation',
    ]);

    $service->confirmRequest($request);

    expect($request->fresh()->status)->toBe('approved');
    Queue::assertPushed(ExecuteActionRequestJob::class);
});

it('confirmRequest throws when not pending_user_confirmation', function (): void {
    $toolRegistry = new ToolRegistry;
    $toolRegistry->register('test', static fn (): string => 'result', 'test', [], 'low');
    $riskClassifier = new RiskClassifier;

    $service = new ActionRequestService($toolRegistry, $riskClassifier);
    $user = User::factory()->create();

    $request = ActionRequest::query()->create([
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'medium',
        'status' => 'approved',
    ]);

    $service->confirmRequest($request);
})->throws(Exception::class, 'Request is not pending user confirmation.');

it('approveRequest updates status to approved and dispatches job', function (): void {
    $toolRegistry = new ToolRegistry;
    $toolRegistry->register('test', static fn (): string => 'result', 'test', [], 'low');
    $riskClassifier = new RiskClassifier;

    $service = new ActionRequestService($toolRegistry, $riskClassifier);
    $user = User::factory()->create();

    $request = ActionRequest::query()->create([
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'high',
        'status' => 'pending_admin_approval',
    ]);

    $service->approveRequest($request);

    expect($request->fresh()->status)->toBe('approved');
    Queue::assertPushed(ExecuteActionRequestJob::class);
});

it('approveRequest throws when not pending_admin_approval', function (): void {
    $toolRegistry = new ToolRegistry;
    $toolRegistry->register('test', static fn (): string => 'result', 'test', [], 'low');
    $riskClassifier = new RiskClassifier;

    $service = new ActionRequestService($toolRegistry, $riskClassifier);
    $user = User::factory()->create();

    $request = ActionRequest::query()->create([
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'high',
        'status' => 'pending_user_confirmation',
    ]);

    $service->approveRequest($request);
})->throws(Exception::class, 'Request is not pending admin approval.');

it('rejectRequest updates status to rejected', function (): void {
    $toolRegistry = new ToolRegistry;
    $toolRegistry->register('test', static fn (): string => 'result', 'test', [], 'low');
    $riskClassifier = new RiskClassifier;

    $service = new ActionRequestService($toolRegistry, $riskClassifier);
    $user = User::factory()->create();

    $request = ActionRequest::query()->create([
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'medium',
        'status' => 'pending_user_confirmation',
    ]);

    $service->rejectRequest($request);

    expect($request->fresh()->status)->toBe('rejected');
});

it('runToolHandler calls the tool handler with args', function (): void {
    $handler = fn (string $a, string $b): string => $a . '-' . $b;
    $toolRegistry = new ToolRegistry;
    $toolRegistry->register('test', $handler, 'test', [
        ['name' => 'a', 'type' => 'string', 'description' => 'A'],
        ['name' => 'b', 'type' => 'string', 'description' => 'B'],
    ], 'low');

    $riskClassifier = new RiskClassifier;
    $service = new ActionRequestService($toolRegistry, $riskClassifier);
    $user = User::factory()->create();

    $request = ActionRequest::query()->create([
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => ['hello' => 'x', 'world' => 'y'],
        'risk_level' => 'low',
        'status' => 'approved',
    ]);

    $result = $service->runToolHandler($request);

    expect($result)->toBe('x-y');
});

it('runToolHandler passes array values to handler when tool_args is array', function (): void {
    $handler = fn (): string => 'result';
    $toolRegistry = new ToolRegistry;
    $toolRegistry->register('test', $handler, 'test', [], 'low');

    $riskClassifier = new RiskClassifier;
    $service = new ActionRequestService($toolRegistry, $riskClassifier);
    $user = User::factory()->create();

    $request = ActionRequest::query()->create([
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'approved',
    ]);

    $result = $service->runToolHandler($request);

    expect($result)->toBe('result');
});

it('createRequest uses pending_user_confirmation status when risk level is unknown', function (): void {
    $toolRegistry = new ToolRegistry;
    $toolRegistry->register('test', static fn (): string => 'result', 'test', [], 'low');

    $riskClassifier = Mockery::mock(RiskClassifier::class);
    $riskClassifier->shouldReceive('classifyRisk')
        ->andReturn('unknown');

    $service = new ActionRequestService($toolRegistry, $riskClassifier);
    $user = User::factory()->create();

    $request = $service->createRequest($user, 'test', []);

    expect($request->status)->toBe('pending_user_confirmation')
        ->and($request->risk_level)->toBe('unknown');

    Queue::assertNotPushed(ExecuteActionRequestJob::class);
});

it('executeRequest updates status to executing when status is not approved', function (): void {
    $toolRegistry = new ToolRegistry;
    $toolRegistry->register('test', static fn (): string => 'result', 'test', [], 'low');
    $riskClassifier = new RiskClassifier;

    $service = new ActionRequestService($toolRegistry, $riskClassifier);
    $user = User::factory()->create();

    $request = ActionRequest::query()->create([
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'medium',
        'status' => 'pending_user_confirmation',
    ]);

    $service->executeRequest($request);

    expect($request->fresh()->status)->toBe('executing');
    Queue::assertPushed(ExecuteActionRequestJob::class);
});

it('runToolHandler throws when tool is not found in registry', function (): void {
    $toolRegistry = new ToolRegistry;
    $riskClassifier = new RiskClassifier;
    $service = new ActionRequestService($toolRegistry, $riskClassifier);
    $user = User::factory()->create();

    $request = ActionRequest::query()->create([
        'user_id' => $user->id,
        'tool_name' => 'nonexistent_tool',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'approved',
    ]);

    $service->runToolHandler($request);
})->throws(Exception::class, 'Unknown tool: nonexistent_tool');

it('runToolHandler passes non-array args to handler as single argument', function (): void {
    $handler = fn (mixed $arg): string => 'got-' . $arg;
    $toolRegistry = new ToolRegistry;
    $toolRegistry->register('test', $handler, 'test', [], 'low');

    $riskClassifier = new RiskClassifier;
    $service = new ActionRequestService($toolRegistry, $riskClassifier);
    $user = User::factory()->create();

    $request = ActionRequest::query()->create([
        'user_id' => $user->id,
        'tool_name' => 'test',
        'tool_args' => 'single_arg',
        'risk_level' => 'low',
        'status' => 'approved',
    ]);

    $result = $service->runToolHandler($request);

    expect($result)->toBe('got-single_arg');
});
