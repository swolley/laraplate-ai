<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\AI\Http\Controllers\ChatController;
use Modules\AI\Http\Requests\InsertConversationRequest;
use Modules\AI\Http\Requests\ListConversationsRequest;
use Modules\AI\Http\Requests\ListMessagesRequest;
use Modules\AI\Http\Requests\SendMessageRequest;
use Modules\AI\Models\ActionRequest;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\AI\Contracts\IChatService;
use Modules\Core\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('insertConversation creates conversation', function (): void {
    Auth::shouldReceive('user')->andReturn($this->user);

    $conversation = Conversation::query()->create([
        'user_id' => $this->user->id,
        'title' => 'Test',
    ]);

    $chatService = Mockery::mock(IChatService::class);
    $chatService->shouldReceive('createConversation')
        ->once()
        ->andReturn($conversation);

    $request = new InsertConversationRequest;
    $request->setContainer(app());
    $request->initialize([], []);
    $request->merge(['title' => 'Test']);
    $request->setRedirector(resolve(Illuminate\Routing\Redirector::class));
    $request->validateResolved();

    $controller = new ChatController($chatService);
    $response = $controller->insertConversation($request);

    expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
});

it('insertConversation aborts when no core user is authenticated', function (): void {
    Auth::shouldReceive('user')->andReturn(null);

    $chatService = Mockery::mock(IChatService::class);
    $request = new InsertConversationRequest;
    $request->setContainer(app());
    $request->initialize([], []);
    $request->merge(['title' => 'Test']);
    $request->setRedirector(resolve(Illuminate\Routing\Redirector::class));
    $request->validateResolved();

    $controller = new ChatController($chatService);

    expect(fn (): Illuminate\Http\JsonResponse => $controller->insertConversation($request))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('listConversations returns paginated conversations', function (): void {
    Auth::shouldReceive('user')->andReturn($this->user);
    Auth::shouldReceive('id')->andReturn($this->user->id);

    Conversation::query()->create(['user_id' => $this->user->id, 'title' => 'Conv 1']);

    $chatService = Mockery::mock(IChatService::class);

    $request = new ListConversationsRequest;
    $request->setContainer(app());
    $request->initialize([], []);
    $request->merge(['per_page' => 15]);
    $request->setRedirector(resolve(Illuminate\Routing\Redirector::class));
    $request->validateResolved();

    $controller = new ChatController($chatService);
    $response = $controller->listConversations($request);

    $data = $response->getData(true);
    expect($response->getStatusCode())->toBe(200)
        ->and($data)->toHaveKey('data');
});

it('detailConversation returns conversation with messages', function (): void {
    Auth::shouldReceive('id')->andReturn($this->user->id);

    $conversation = Conversation::query()->create(['user_id' => $this->user->id, 'title' => 'Test']);
    $conversation->messages()->create(['role' => 'user', 'content' => 'Hello']);

    $chatService = Mockery::mock(IChatService::class);

    $controller = new ChatController($chatService);
    $response = $controller->detailConversation($conversation);

    $data = $response->getData(true);
    expect($response->getStatusCode())->toBe(200)
        ->and($data['data']['id'])->toBe($conversation->id);
});

it('deleteConversation deletes conversation', function (): void {
    Auth::shouldReceive('id')->andReturn($this->user->id);

    $conversation = Conversation::query()->create(['user_id' => $this->user->id]);

    $chatService = Mockery::mock(IChatService::class);

    $controller = new ChatController($chatService);
    $response = $controller->deleteConversation($conversation);

    expect($response->getStatusCode())->toBe(200)
        ->and(Conversation::query()->find($conversation->id))->toBeNull();
});

it('insertMessage sends message and returns response', function (): void {
    Auth::shouldReceive('id')->andReturn($this->user->id);

    $conversation = Conversation::query()->create(['user_id' => $this->user->id]);
    $message = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Hi']);

    $chatService = Mockery::mock(IChatService::class);
    $chatService->shouldReceive('sendMessage')
        ->once()
        ->andReturn($message);

    $request = new SendMessageRequest;
    $request->setContainer(app());
    $request->initialize([], []);
    $request->merge(['message' => 'Hello']);
    $request->setRedirector(resolve(Illuminate\Routing\Redirector::class));
    $request->validateResolved();

    $controller = new ChatController($chatService);
    $response = $controller->insertMessage($request, $conversation);

    expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
});

it('insertMessage aborts when validated message is not a string', function (): void {
    Auth::shouldReceive('id')->andReturn($this->user->id);

    $conversation = Conversation::query()->create(['user_id' => $this->user->id]);
    $chatService = Mockery::mock(IChatService::class);

    $request = new class extends SendMessageRequest
    {
        /**
         * @return array<string, mixed>
         */
        #[Override]
        public function validated($key = null, $default = null): mixed
        {
            return ['message' => ['not a string']];
        }
    };

    $request->setContainer(app());
    $request->initialize([], []);

    $controller = new ChatController($chatService);

    expect(fn (): Illuminate\Http\JsonResponse => $controller->insertMessage($request, $conversation))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('streamMessage returns StreamedResponse and invokes on_chunk callback', function (): void {
    Auth::shouldReceive('id')->andReturn($this->user->id);

    $conversation = Conversation::query()->create(['user_id' => $this->user->id]);
    $streamMessage = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Hello world']);

    $chatService = Mockery::mock(IChatService::class);
    $chatService->shouldReceive('sendMessageStream')
        ->once()
        ->with($conversation, 'Hello', null, Mockery::type('callable'))
        ->andReturnUsing(function ($conv, $msg, $ctx, $on_chunk) use ($streamMessage): Message {
            $on_chunk('Hello ');
            $on_chunk('world');

            return $streamMessage;
        });

    $request = new SendMessageRequest;
    $request->setContainer(app());
    $request->initialize([], []);
    $request->merge(['message' => 'Hello']);
    $request->setRedirector(resolve(Illuminate\Routing\Redirector::class));
    $request->validateResolved();

    $controller = new ChatController($chatService);
    $response = $controller->streamMessage($request, $conversation);

    expect($response)->toBeInstanceOf(Symfony\Component\HttpFoundation\StreamedResponse::class);

    // StreamedResponse uses ob_flush in the controller; keep two nested buffers so
    // flushes stay in-memory and do not pollute test output.
    ob_start();
    ob_start();
    $response->sendContent();
    ob_end_clean();
    ob_end_clean();
});

it('authorizeConversationAccess aborts for unauthorized', function (): void {
    $otherUser = User::factory()->create();
    Auth::shouldReceive('id')->andReturn($otherUser->id);

    $conversation = Conversation::query()->create(['user_id' => $this->user->id]);

    $chatService = Mockery::mock(IChatService::class);

    $controller = new ChatController($chatService);

    expect(fn (): Illuminate\Http\JsonResponse => $controller->detailConversation($conversation))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('listMessages returns paginated messages for conversation', function (): void {
    Auth::shouldReceive('id')->andReturn($this->user->id);

    $conversation = Conversation::query()->create(['user_id' => $this->user->id]);
    $conversation->messages()->create(['role' => 'user', 'content' => 'Hello']);
    $conversation->messages()->create(['role' => 'assistant', 'content' => 'Hi there']);

    $chatService = Mockery::mock(IChatService::class);

    $request = new ListMessagesRequest;
    $request->setContainer(app());
    $request->initialize([], []);
    $request->merge(['per_page' => 50]);
    $request->setRedirector(resolve(Illuminate\Routing\Redirector::class));
    $request->validateResolved();

    $controller = new ChatController($chatService);
    $response = $controller->listMessages($request, $conversation);

    $data = $response->getData(true);
    expect($response->getStatusCode())->toBe(200)
        ->and($data)->toHaveKey('data')
        ->and($data['data'])->toHaveCount(2);
});

it('sendMessageWithTools returns message and action requests', function (): void {
    Auth::shouldReceive('id')->andReturn($this->user->id);

    $conversation = Conversation::query()->create(['user_id' => $this->user->id]);
    $message = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Response']);
    $actionRequest = ActionRequest::query()->create([
        'user_id' => $this->user->id,
        'conversation_id' => $conversation->id,
        'tool_name' => 'test_tool',
        'tool_args' => ['key' => 'value'],
        'risk_level' => 'medium',
        'status' => 'pending_user_confirmation',
    ]);

    $chatService = Mockery::mock(IChatService::class);
    $chatService->shouldReceive('sendMessageWithTools')
        ->once()
        ->with($conversation, 'Hello', null)
        ->andReturn([
            'message' => $message,
            'action_requests' => [$actionRequest],
        ]);

    $request = new SendMessageRequest;
    $request->setContainer(app());
    $request->initialize([], []);
    $request->merge(['message' => 'Hello']);
    $request->setRedirector(resolve(Illuminate\Routing\Redirector::class));
    $request->validateResolved();

    $controller = new ChatController($chatService);
    $response = $controller->sendMessageWithTools($request, $conversation);

    $data = $response->getData(true);
    expect($response->getStatusCode())->toBe(Response::HTTP_CREATED)
        ->and($data)->toHaveKey('data')
        ->and($data['data'])->toHaveKey('message')
        ->and($data['data'])->toHaveKey('action_requests')
        ->and($data['data']['action_requests'])->toHaveCount(1)
        ->and($data['data']['action_requests'][0]['tool_name'])->toBe('test_tool');
});

it('sendMessageWithTools passes context when provided', function (): void {
    Auth::shouldReceive('id')->andReturn($this->user->id);

    $conversation = Conversation::query()->create(['user_id' => $this->user->id]);
    $message = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Response']);

    $chatService = Mockery::mock(IChatService::class);
    $chatService->shouldReceive('sendMessageWithTools')
        ->once()
        ->with($conversation, 'Hello', ['page' => 'dashboard'])
        ->andReturn([
            'message' => $message,
            'action_requests' => [],
        ]);

    $request = new SendMessageRequest;
    $request->setContainer(app());
    $request->initialize([], []);
    $request->merge(['message' => 'Hello', 'context' => ['page' => 'dashboard']]);
    $request->setRedirector(resolve(Illuminate\Routing\Redirector::class));
    $request->validateResolved();

    $controller = new ChatController($chatService);
    $response = $controller->sendMessageWithTools($request, $conversation);

    expect($response->getStatusCode())->toBe(Response::HTTP_CREATED);
});
