<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\AI\Http\Controllers\ActionRequestController;
use Modules\AI\Http\Requests\ApproveActionRequest;
use Modules\AI\Http\Requests\RejectActionRequest;
use Modules\AI\Models\ActionRequest;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\ActionRequestService;
use Modules\Core\Models\User;

class AdminUser extends User
{
    public function hasRole(array|string $roles): bool
    {
        return true;
    }
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $admin = User::factory()->create();
    $this->admin = new AdminUser;
    $this->admin->forceFill($admin->getAttributes());
    $this->admin->exists = true;
    $this->admin->syncOriginal();

    $this->conversation = Conversation::query()->create(['user_id' => $this->user->id]);
});

it('list returns paginated results for regular user', function (): void {
    Auth::shouldReceive('user')->andReturn($this->user);

    ActionRequest::query()->create([
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'pending',
    ]);

    request()->merge(['per_page' => 20]);
    $service = createActionRequestService();
    app()->instance(ActionRequestService::class, $service);

    $controller = new ActionRequestController($service);
    $response = $controller->list();

    $data = $response->getData(true);
    expect($response->getStatusCode())->toBe(200)
        ->and($data)->toHaveKey('data');
});

it('list returns admin-scoped results for admin', function (): void {
    Auth::shouldReceive('user')->andReturn($this->admin);

    ActionRequest::query()->create([
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'high',
        'status' => 'pending_admin_approval',
    ]);

    request()->merge(['per_page' => 20]);
    $service = createActionRequestService();
    app()->instance(ActionRequestService::class, $service);

    $controller = new ActionRequestController($service);
    $response = $controller->list();

    $data = $response->getData(true);
    expect($response->getStatusCode())->toBe(200)
        ->and($data)->toHaveKey('data');
});

it('detail returns action request details', function (): void {
    Auth::shouldReceive('user')->andReturn($this->user);

    $actionRequest = ActionRequest::query()->create([
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'pending',
    ]);

    $service = createActionRequestService();
    app()->instance(ActionRequestService::class, $service);

    $controller = new ActionRequestController($service);
    $response = $controller->detail($actionRequest);

    $data = $response->getData(true);
    expect($response->getStatusCode())->toBe(200)
        ->and($data['data']['tool_name'])->toBe('test');
});

it('confirm confirms pending_user_confirmation request', function (): void {
    Auth::shouldReceive('user')->andReturn($this->user);
    Auth::shouldReceive('id')->andReturn($this->user->id);
    Illuminate\Support\Facades\Queue::fake();

    $actionRequest = ActionRequest::query()->create([
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'medium',
        'status' => 'pending_user_confirmation',
    ]);

    $service = createActionRequestService();
    app()->instance(ActionRequestService::class, $service);

    $controller = new ActionRequestController($service);
    $response = $controller->confirm($actionRequest);

    $data = $response->getData(true);
    expect($response->getStatusCode())->toBe(200)
        ->and($data['data'])->toHaveKey('status');
});

it('confirm returns error for non-pending request', function (): void {
    Auth::shouldReceive('user')->andReturn($this->user);
    Auth::shouldReceive('id')->andReturn($this->user->id);

    $actionRequest = ActionRequest::query()->create([
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'approved',
    ]);

    $service = createActionRequestService();
    app()->instance(ActionRequestService::class, $service);

    $controller = new ActionRequestController($service);
    $response = $controller->confirm($actionRequest);

    expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
});

it('approve returns forbidden for non-admin', function (): void {
    Auth::shouldReceive('user')->andReturn($this->user);

    $actionRequest = ActionRequest::query()->create([
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'high',
        'status' => 'pending_admin_approval',
    ]);

    $approveRequest = new ApproveActionRequest;
    $approveRequest->setContainer(app());
    $approveRequest->initialize([]);

    $service = createActionRequestService();
    app()->instance(ActionRequestService::class, $service);

    $controller = new ActionRequestController($service);
    $response = $controller->approve($approveRequest, $actionRequest);

    expect($response->getStatusCode())->toBe(Response::HTTP_FORBIDDEN);
});

it('approve approves pending_admin_approval request', function (): void {
    Auth::shouldReceive('user')->andReturn($this->admin);
    Illuminate\Support\Facades\Queue::fake();

    $actionRequest = ActionRequest::query()->create([
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'high',
        'status' => 'pending_admin_approval',
    ]);

    $approveRequest = new ApproveActionRequest;
    $approveRequest->setContainer(app());
    $approveRequest->initialize([]);

    $service = createActionRequestService();
    app()->instance(ActionRequestService::class, $service);

    $controller = new ActionRequestController($service);
    $response = $controller->approve($approveRequest, $actionRequest);

    expect($response->getStatusCode())->toBe(200);
});

it('approve returns error when action request is not pending admin approval', function (): void {
    Auth::shouldReceive('user')->andReturn($this->admin);

    $actionRequest = ActionRequest::query()->create([
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'high',
        'status' => 'approved',
    ]);

    $approveRequest = new ApproveActionRequest;
    $approveRequest->setContainer(app());
    $approveRequest->initialize([]);

    $service = createActionRequestService();
    app()->instance(ActionRequestService::class, $service);

    $controller = new ActionRequestController($service);
    $response = $controller->approve($approveRequest, $actionRequest);

    $data = $response->getData(true);
    expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST)
        ->and($data['error'])->toBe('This action request is not pending admin approval.');
});

it('reject rejects pending request', function (): void {
    Auth::shouldReceive('user')->andReturn($this->user);
    Auth::shouldReceive('id')->andReturn($this->user->id);

    $actionRequest = ActionRequest::query()->create([
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'medium',
        'status' => 'pending_user_confirmation',
    ]);

    $rejectRequest = new RejectActionRequest;
    $rejectRequest->setContainer(app());
    $rejectRequest->initialize([]);

    $service = createActionRequestService();
    app()->instance(ActionRequestService::class, $service);

    $controller = new ActionRequestController($service);
    $response = $controller->reject($rejectRequest, $actionRequest);

    $data = $response->getData(true);
    expect($response->getStatusCode())->toBe(200)
        ->and($data['data']['status'])->toBe('rejected');
});

it('reject returns error for non-rejectable status', function (): void {
    Auth::shouldReceive('user')->andReturn($this->user);

    $actionRequest = ActionRequest::query()->create([
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'completed',
    ]);

    $rejectRequest = new RejectActionRequest;
    $rejectRequest->setContainer(app());
    $rejectRequest->initialize([]);

    $service = createActionRequestService();
    app()->instance(ActionRequestService::class, $service);

    $controller = new ActionRequestController($service);
    $response = $controller->reject($rejectRequest, $actionRequest);

    expect($response->getStatusCode())->toBe(Response::HTTP_BAD_REQUEST);
});

it('authorizeAccess aborts for unauthorized user', function (): void {
    $otherUser = User::factory()->create();
    Auth::shouldReceive('user')->andReturn($otherUser);

    $actionRequest = ActionRequest::query()->create([
        'conversation_id' => $this->conversation->id,
        'user_id' => $this->user->id,
        'tool_name' => 'test',
        'tool_args' => [],
        'risk_level' => 'low',
        'status' => 'pending',
    ]);

    $service = createActionRequestService();
    app()->instance(ActionRequestService::class, $service);

    $controller = new ActionRequestController($service);

    expect(fn (): Illuminate\Http\JsonResponse => $controller->detail($actionRequest))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});
