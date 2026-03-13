<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\AI\Services\ChatService;
use Modules\AI\Services\DocumentationService;
use Modules\AI\Services\GuardrailsService;
use Modules\AI\Services\MemoryService;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Mockery::close();
});

it('createConversation creates a Conversation record', function (): void {
    $user = User::factory()->create();
    $service = new ChatService;

    $conversation = $service->createConversation($user, 'Test Title', 'You are helpful', ['key' => 'value']);

    expect($conversation)->toBeInstanceOf(Conversation::class)
        ->and($conversation->user_id)->toBe($user->id)
        ->and($conversation->title)->toBe('Test Title')
        ->and($conversation->system_message)->toBe('You are helpful')
        ->and($conversation->metadata)->toBe(['key' => 'value']);
});

it('buildAgent returns ChatAgent instance', function (): void {
    config()->set('ai.features.chat.default_provider', 'ollama');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');
    config()->set('ai.providers.ollama.model', 'llama3.2:3b');

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => false,
    ]);

    $service = new ChatService;

    expect($service->buildAgent($conversation))->toBeInstanceOf(ChatAgent::class);
});

it('looksLikeQuestion detects questions ending with question mark', function (): void {
    config()->set('ai.features.faq.question_detection.enabled', true);
    app()->setLocale('en');

    $service = new ChatService;
    $method = new ReflectionMethod($service, 'looksLikeQuestion');

    expect($method->invoke($service, 'What is this?'))->toBeTrue()
        ->and($method->invoke($service, 'Hello world'))->toBeFalse();
});

it('looksLikeQuestion detects Italian question words', function (): void {
    config()->set('ai.features.faq.question_detection.enabled', true);
    app()->setLocale('it');

    $service = new ChatService;
    $method = new ReflectionMethod($service, 'looksLikeQuestion');

    expect($method->invoke($service, 'cosa posso fare'))->toBeTrue()
        ->and($method->invoke($service, 'come funziona questo'))->toBeTrue();
});

it('looksLikeQuestion returns false when detection disabled', function (): void {
    config()->set('ai.features.faq.question_detection.enabled', false);

    $service = new ChatService;
    $method = new ReflectionMethod($service, 'looksLikeQuestion');

    expect($method->invoke($service, 'What is this?'))->toBeFalse();
});

it('getQuestionWordsForLocale returns correct words for it', function (): void {
    $service = new ChatService;
    $method = new ReflectionMethod($service, 'getQuestionWordsForLocale');

    $words = $method->invoke($service, 'it');

    expect($words)->toContain('cosa')
        ->toContain('come')
        ->toContain('perché');
});

it('getQuestionWordsForLocale returns correct words for de', function (): void {
    $service = new ChatService;
    $method = new ReflectionMethod($service, 'getQuestionWordsForLocale');

    $words = $method->invoke($service, 'de');

    expect($words)->toContain('was')
        ->toContain('wie');
});

it('getQuestionWordsForLocale returns correct words for es', function (): void {
    $service = new ChatService;
    $method = new ReflectionMethod($service, 'getQuestionWordsForLocale');

    $words = $method->invoke($service, 'es');

    expect($words)->toContain('qué')
        ->toContain('cómo');
});

it('getQuestionWordsForLocale returns correct words for fr', function (): void {
    $service = new ChatService;
    $method = new ReflectionMethod($service, 'getQuestionWordsForLocale');

    $words = $method->invoke($service, 'fr');

    expect($words)->toContain('quoi')
        ->toContain('comment');
});

it('getQuestionWordsForLocale returns default for unknown locale', function (): void {
    $service = new ChatService;
    $method = new ReflectionMethod($service, 'getQuestionWordsForLocale');

    $words = $method->invoke($service, 'xx');

    expect($words)->toContain('what')
        ->toContain('how')
        ->toContain('why');
});

it('applyInputGuardrails returns message unchanged when guardrails disabled', function (): void {
    config()->set('ai.features.guardrails.enabled', false);

    $service = new ChatService;
    $method = new ReflectionMethod($service, 'applyInputGuardrails');

    $result = $method->invoke($service, 'Original message');

    expect($result)->toBe('Original message');
});

it('applyInputGuardrails calls GuardrailsService when guardrails enabled', function (): void {
    config()->set('ai.features.guardrails.enabled', true);

    $guardrailsMock = Mockery::mock(GuardrailsService::class);
    $guardrailsMock->shouldReceive('checkPromptInjection')
        ->once()
        ->with('User input')
        ->andReturn('Sanitized input');
    app()->instance(GuardrailsService::class, $guardrailsMock);

    $service = new ChatService;
    $method = new ReflectionMethod($service, 'applyInputGuardrails');
    $result = $method->invoke($service, 'User input');

    expect($result)->toBe('Sanitized input');
});

it('sendMessage uses RAG path when DocumentationService available and question detected', function (): void {
    config()->set('ai.features.guardrails.enabled', false);
    config()->set('ai.features.faq.question_detection.enabled', true);
    app()->setLocale('en');

    $docServiceMock = Mockery::mock(DocumentationService::class);
    $docServiceMock->shouldReceive('isAvailable')->andReturn(true);
    $docServiceMock->shouldReceive('answerQuestion')
        ->once()
        ->with('What is this?')
        ->andReturn(['answer' => 'RAG answer', 'citations' => []]);
    app()->instance(DocumentationService::class, $docServiceMock);

    $memoryMock = Mockery::mock(MemoryService::class);
    $memoryMock->shouldReceive('shouldSummarize')->andReturn(false);
    app()->instance(MemoryService::class, $memoryMock);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);

    $service = new ChatService;
    $message = $service->sendMessage($conversation, 'What is this?');

    expect($message)->toBeInstanceOf(Message::class)
        ->and($message->content)->toBe('RAG answer')
        ->and($message->role)->toBe('assistant')
        ->and($message->metadata)->toHaveKey('citations');
});

it('sendMessage uses RAG path when context has use_rag true', function (): void {
    config()->set('ai.features.guardrails.enabled', false);
    config()->set('ai.features.faq.question_detection.enabled', false);

    $docServiceMock = Mockery::mock(DocumentationService::class);
    $docServiceMock->shouldReceive('isAvailable')->andReturn(true);
    $docServiceMock->shouldReceive('answerQuestion')
        ->once()
        ->with('Hello')
        ->andReturn(['answer' => 'RAG reply', 'citations' => []]);
    app()->instance(DocumentationService::class, $docServiceMock);

    $memoryMock = Mockery::mock(MemoryService::class);
    $memoryMock->shouldReceive('shouldSummarize')->andReturn(false);
    app()->instance(MemoryService::class, $memoryMock);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);

    $service = new ChatService;
    $message = $service->sendMessage($conversation, 'Hello', ['use_rag' => true]);

    expect($message->content)->toBe('RAG reply');
});

it('sendMessage via agent returns assistant message when RAG not applicable', function (): void {
    config()->set('ai.features.guardrails.enabled', false);
    config()->set('ai.features.faq.question_detection.enabled', false);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);

    $mockAgentHandler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $mockAgentHandler->shouldReceive('getMessage')
        ->andReturn(new NeuronAI\Chat\Messages\AssistantMessage('AI response'));

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')
        ->with(Mockery::type(NeuronAI\Chat\Messages\UserMessage::class))
        ->andReturn($mockAgentHandler);

    $memoryMock = Mockery::mock(MemoryService::class);
    $memoryMock->shouldReceive('getContextForNewMessage')->andReturn(null);
    $memoryMock->shouldReceive('shouldSummarize')->andReturn(false);
    app()->instance(MemoryService::class, $memoryMock);

    $docMock = Mockery::mock(DocumentationService::class);
    $docMock->shouldReceive('isAvailable')->andReturn(false);
    app()->instance(DocumentationService::class, $docMock);

    $chatService = Mockery::mock(ChatService::class)->makePartial();
    $chatService->shouldReceive('buildAgent')->andReturn($mockAgent);

    $message = $chatService->sendMessage($conversation, 'Hello');

    expect($message)->toBeInstanceOf(Message::class)
        ->and($message->content)->toBe('AI response')
        ->and($message->role)->toBe('assistant');
});

it('sendMessage calls checkAndCreateSummaryIfNeeded', function (): void {
    config()->set('ai.features.guardrails.enabled', false);
    config()->set('ai.features.faq.question_detection.enabled', false);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);

    $mockAgentHandler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $mockAgentHandler->shouldReceive('getMessage')
        ->andReturn(new NeuronAI\Chat\Messages\AssistantMessage('Response'));

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')->andReturn($mockAgentHandler);

    $memoryMock = Mockery::mock(MemoryService::class);
    $memoryMock->shouldReceive('getContextForNewMessage')->andReturn(null);
    $memoryMock->shouldReceive('shouldSummarize')->once()->andReturn(false);
    app()->instance(MemoryService::class, $memoryMock);

    $docMock = Mockery::mock(DocumentationService::class);
    $docMock->shouldReceive('isAvailable')->andReturn(false);
    app()->instance(DocumentationService::class, $docMock);

    $chatService = Mockery::mock(ChatService::class)->makePartial();
    $chatService->shouldReceive('buildAgent')->andReturn($mockAgent);

    $chatService->sendMessage($conversation, 'Hi');
});

it('sendMessage throws on agent error', function (): void {
    config()->set('ai.features.guardrails.enabled', false);
    config()->set('ai.features.faq.question_detection.enabled', false);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')
        ->andThrow(new Exception('Provider error'));

    $memoryMock = Mockery::mock(MemoryService::class);
    $memoryMock->shouldReceive('getContextForNewMessage')->andReturn(null);
    app()->instance(MemoryService::class, $memoryMock);

    $docMock = Mockery::mock(DocumentationService::class);
    $docMock->shouldReceive('isAvailable')->andReturn(false);
    app()->instance(DocumentationService::class, $docMock);

    $chatService = Mockery::mock(ChatService::class)->makePartial();
    $chatService->shouldReceive('buildAgent')->andReturn($mockAgent);

    $chatService->sendMessage($conversation, 'Hello');
})->throws(Exception::class, 'Provider error');

it('sendMessageStream streams chunks and returns message', function (): void {
    config()->set('ai.features.guardrails.enabled', false);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);

    $chunk1 = new NeuronAI\Chat\Messages\Stream\Chunks\TextChunk('msg-1', 'Hello ');
    $chunk2 = new NeuronAI\Chat\Messages\Stream\Chunks\TextChunk('msg-1', 'world');

    $streamHandler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $streamHandler->shouldReceive('events')->andReturnUsing(function () use ($chunk1, $chunk2): Generator {
        yield $chunk1;

        yield $chunk2;
    });

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('stream')
        ->with(Mockery::type(NeuronAI\Chat\Messages\UserMessage::class))
        ->andReturn($streamHandler);

    $memoryMock = Mockery::mock(MemoryService::class);
    $memoryMock->shouldReceive('getContextForNewMessage')->andReturn(null);
    app()->instance(MemoryService::class, $memoryMock);

    $chatService = Mockery::mock(ChatService::class)->makePartial();
    $chatService->shouldReceive('buildAgent')->andReturn($mockAgent);

    $chunks_received = [];
    $message = $chatService->sendMessageStream(
        $conversation,
        'Hi',
        null,
        function (string $delta) use (&$chunks_received): void {
            $chunks_received[] = $delta;
        },
    );

    expect($message)->toBeInstanceOf(Message::class)
        ->and($message->content)->toBe('Hello world')
        ->and($message->role)->toBe('assistant')
        ->and($chunks_received)->toBe(['Hello ', 'world']);
});

it('sendMessageStream skips empty and non-TextChunk chunks', function (): void {
    config()->set('ai.features.guardrails.enabled', false);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);

    $textChunk = new NeuronAI\Chat\Messages\Stream\Chunks\TextChunk('msg-1', 'Only this');
    $emptyChunk = new NeuronAI\Chat\Messages\Stream\Chunks\TextChunk('msg-1', '');

    $otherChunk = new stdClass;

    $streamHandler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $streamHandler->shouldReceive('events')->andReturnUsing(function () use ($otherChunk, $emptyChunk, $textChunk): Generator {
        yield $otherChunk;

        yield $emptyChunk;

        yield $textChunk;
    });

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('stream')->andReturn($streamHandler);

    $memoryMock = Mockery::mock(MemoryService::class);
    $memoryMock->shouldReceive('getContextForNewMessage')->andReturn(null);
    app()->instance(MemoryService::class, $memoryMock);

    $chatService = Mockery::mock(ChatService::class)->makePartial();
    $chatService->shouldReceive('buildAgent')->andReturn($mockAgent);

    $chunks = [];
    $message = $chatService->sendMessageStream($conversation, 'Hi', null, function (string $d) use (&$chunks): void {
        $chunks[] = $d;
    });

    expect($message->content)->toBe('Only this')
        ->and($chunks)->toBe(['Only this']);
});

it('sendMessageStream throws on error', function (): void {
    config()->set('ai.features.guardrails.enabled', false);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('stream')->andThrow(new Exception('Stream error'));

    $memoryMock = Mockery::mock(MemoryService::class);
    $memoryMock->shouldReceive('getContextForNewMessage')->andReturn(null);
    app()->instance(MemoryService::class, $memoryMock);

    $chatService = Mockery::mock(ChatService::class)->makePartial();
    $chatService->shouldReceive('buildAgent')->andReturn($mockAgent);

    $chatService->sendMessageStream($conversation, 'Hi', null, fn (): null => null);
})->throws(Exception::class, 'Stream error');

it('sendMessageWithTools without tools enabled returns agent response', function (): void {
    config()->set('ai.features.guardrails.enabled', false);
    config()->set('ai.features.tools.enabled', false);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);

    $mockAgentHandler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $mockAgentHandler->shouldReceive('getMessage')
        ->andReturn(new NeuronAI\Chat\Messages\AssistantMessage('No tools response'));

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')->andReturn($mockAgentHandler);

    $memoryMock = Mockery::mock(MemoryService::class);
    $memoryMock->shouldReceive('getContextForNewMessage')->andReturn(null);
    app()->instance(MemoryService::class, $memoryMock);

    $chatService = Mockery::mock(ChatService::class)->makePartial();
    $chatService->shouldReceive('buildAgent')->andReturn($mockAgent);

    $result = $chatService->sendMessageWithTools($conversation, 'Do something');

    expect($result)->toHaveKey('message')
        ->and($result)->toHaveKey('action_requests')
        ->and($result['message']->content)->toBe('No tools response')
        ->and($result['action_requests'])->toBe([]);
});

it('sendMessageWithTools with tools enabled returns result', function (): void {
    config()->set('ai.features.guardrails.enabled', false);
    config()->set('ai.features.tools.enabled', true);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);

    $mockAgentHandler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $mockAgentHandler->shouldReceive('getMessage')
        ->andReturn(new NeuronAI\Chat\Messages\AssistantMessage('Tool response'));

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')->andReturn($mockAgentHandler);
    $mockAgent->shouldReceive('addTool')->atLeast()->once();

    $memoryMock = Mockery::mock(MemoryService::class);
    $memoryMock->shouldReceive('getContextForNewMessage')->andReturn(null);
    app()->instance(MemoryService::class, $memoryMock);

    $toolRegistry = new Modules\AI\Services\Tools\ToolRegistry;
    $toolRegistry->register('test_tool', fn (): string => 'result', 'Test tool', [], 'low');
    app()->instance(Modules\AI\Services\Tools\ToolRegistry::class, $toolRegistry);

    $actionRequestService = createActionRequestService();
    app()->instance(Modules\AI\Services\ActionRequestService::class, $actionRequestService);

    $riskClassifier = new Modules\AI\Services\Tools\RiskClassifier(['test_tool' => ['risk_level' => 'low']]);
    app()->instance(Modules\AI\Services\Tools\RiskClassifier::class, $riskClassifier);

    $chatService = Mockery::mock(ChatService::class)->makePartial();
    $chatService->shouldReceive('buildAgent')->andReturn($mockAgent);

    $result = $chatService->sendMessageWithTools($conversation, 'Run test_tool');

    expect($result)->toHaveKey('message')
        ->and($result['message']->content)->toBe('Tool response');
});

it('sendMessage triggers summary creation when shouldSummarize is true', function (): void {
    config()->set('ai.features.guardrails.enabled', false);
    config()->set('ai.features.faq.question_detection.enabled', false);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);

    $mockAgentHandler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $mockAgentHandler->shouldReceive('getMessage')
        ->andReturn(new NeuronAI\Chat\Messages\AssistantMessage('Summarized'));

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')->andReturn($mockAgentHandler);

    $memoryMock = Mockery::mock(MemoryService::class);
    $memoryMock->shouldReceive('getContextForNewMessage')->andReturn(null);
    $memoryMock->shouldReceive('shouldSummarize')->once()->andReturn(true);
    $memoryMock->shouldReceive('createSummarySnapshot')
        ->once()
        ->with(Mockery::type(Conversation::class), Mockery::type(ChatAgent::class));
    app()->instance(MemoryService::class, $memoryMock);

    $docMock = Mockery::mock(DocumentationService::class);
    $docMock->shouldReceive('isAvailable')->andReturn(false);
    app()->instance(DocumentationService::class, $docMock);

    $chatService = Mockery::mock(ChatService::class)->makePartial();
    $chatService->shouldReceive('buildAgent')->andReturn($mockAgent);

    $message = $chatService->sendMessage($conversation, 'Summarize me');

    expect($message->content)->toBe('Summarized');
});

it('sendMessageWithTools throws on error', function (): void {
    config()->set('ai.features.guardrails.enabled', false);
    config()->set('ai.features.tools.enabled', true);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')->andThrow(new Exception('Tool error'));
    $mockAgent->shouldReceive('addTool');

    $memoryMock = Mockery::mock(MemoryService::class);
    $memoryMock->shouldReceive('getContextForNewMessage')->andReturn(null);
    app()->instance(MemoryService::class, $memoryMock);

    $toolRegistry = new Modules\AI\Services\Tools\ToolRegistry;
    $toolRegistry->register('t', fn (): string => 'x', 'Tool', [], 'low');
    app()->instance(Modules\AI\Services\Tools\ToolRegistry::class, $toolRegistry);
    app()->instance(Modules\AI\Services\ActionRequestService::class, createActionRequestService());
    app()->instance(Modules\AI\Services\Tools\RiskClassifier::class, new Modules\AI\Services\Tools\RiskClassifier([]));

    $chatService = Mockery::mock(ChatService::class)->makePartial();
    $chatService->shouldReceive('buildAgent')->andReturn($mockAgent);

    $chatService->sendMessageWithTools($conversation, 'Do it');
})->throws(Exception::class, 'Tool error');

it('getQuestionWordsForLocale uses configured words when available', function (): void {
    config()->set('ai.features.faq.question_detection.words', [
        'it' => ['domanda', 'chiedi'],
    ]);

    $service = new ChatService;
    $method = new ReflectionMethod($service, 'getQuestionWordsForLocale');

    $words = $method->invoke($service, 'it');

    expect($words)->toBe(['domanda', 'chiedi']);
});

it('getQuestionWordsForLocale uses base locale when full locale has underscore', function (): void {
    $service = new ChatService;
    $method = new ReflectionMethod($service, 'getQuestionWordsForLocale');

    $words = $method->invoke($service, 'it_IT');

    expect($words)->toContain('cosa')
        ->toContain('come');
});

it('buildAgent appends memory context to system prompt when available', function (): void {
    config()->set('ai.features.chat.default_provider', 'ollama');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');
    config()->set('ai.providers.ollama.model', 'llama3.2:3b');

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'system_message' => 'Base system prompt',
        'memory_enabled' => true,
    ]);

    $memoryMock = Mockery::mock(MemoryService::class);
    $memoryMock->shouldReceive('getContextForNewMessage')
        ->once()
        ->with(Mockery::type(Conversation::class))
        ->andReturn('Memory: user likes blue');
    app()->instance(MemoryService::class, $memoryMock);

    $service = new ChatService;
    $agent = $service->buildAgent($conversation);

    expect($agent)->toBeInstanceOf(ChatAgent::class);
    $reflection = new ReflectionMethod($agent, 'instructions');
    $instructions = $reflection->invoke($agent);

    expect($instructions)->toContain('Base system prompt')
        ->and($instructions)->toContain('Memory: user likes blue');
});
