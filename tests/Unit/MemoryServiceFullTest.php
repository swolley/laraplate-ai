<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\ConversationSummary;
use Modules\AI\Models\Message;
use Modules\AI\Services\MemoryService;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = new MemoryService;
});

it('shouldSummarize returns false when memory_enabled is false', function (): void {
    config()->set('ai.features.chat.enable_summary', true);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => false,
    ]);

    expect($this->service->shouldSummarize($conversation))->toBeFalse();
});

it('shouldSummarize returns false when config disabled', function (): void {
    config()->set('ai.features.chat.enable_summary', false);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
    ]);

    expect($this->service->shouldSummarize($conversation))->toBeFalse();
});

it('shouldSummarize returns true when message count >= threshold', function (): void {
    config()->set('ai.features.chat.enable_summary', true);
    config()->set('ai.features.chat.summary_threshold', 5);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
    ]);

    for ($i = 0; $i < 5; $i++) {
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => "Message {$i}",
        ]);
    }

    expect($this->service->shouldSummarize($conversation))->toBeTrue();
});

it('shouldSummarize returns true when messages since last summary >= threshold', function (): void {
    config()->set('ai.features.chat.enable_summary', true);
    config()->set('ai.features.chat.summary_threshold', 3);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
    ]);

    for ($i = 0; $i < 2; $i++) {
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => "Message {$i}",
        ]);
    }

    ConversationSummary::query()->create([
        'conversation_id' => $conversation->id,
        'summary' => 'Previous summary',
        'message_count' => 2,
    ]);

    for ($i = 0; $i < 3; $i++) {
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => "New message {$i}",
        ]);
    }

    expect($this->service->shouldSummarize($conversation))->toBeTrue();
});

it('getContextForNewMessage returns null when memory disabled', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => false,
        'summary' => 'Some summary',
    ]);

    expect($this->service->getContextForNewMessage($conversation))->toBeNull();
});

it('getContextForNewMessage returns null when no summary', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
        'summary' => null,
    ]);

    expect($this->service->getContextForNewMessage($conversation))->toBeNull();
});

it('getContextForNewMessage returns summary context string', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
        'summary' => 'User discussed project deadlines.',
    ]);

    $context = $this->service->getContextForNewMessage($conversation);

    expect($context)->toContain('Previous conversation summary:')
        ->toContain('User discussed project deadlines.');
});

it('forgetConversation deletes summaries and sets summary to null', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
        'summary' => 'Old summary',
    ]);

    ConversationSummary::query()->create([
        'conversation_id' => $conversation->id,
        'summary' => 'Snapshot',
        'message_count' => 5,
    ]);

    $this->service->forgetConversation($conversation);

    $conversation->refresh();
    expect($conversation->summary)->toBeNull()
        ->and($conversation->summaries()->count())->toBe(0);
});

it('setMemoryEnabled disables memory and calls forgetConversation', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
        'summary' => 'Summary',
    ]);

    ConversationSummary::query()->create([
        'conversation_id' => $conversation->id,
        'summary' => 'Snapshot',
        'message_count' => 1,
    ]);

    $this->service->setMemoryEnabled($conversation, false);

    $conversation->refresh();
    expect($conversation->memory_enabled)->toBeFalse()
        ->and($conversation->summary)->toBeNull()
        ->and($conversation->summaries()->count())->toBe(0);
});

it('setMemoryEnabled enables memory', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => false,
    ]);

    $this->service->setMemoryEnabled($conversation, true);

    $conversation->refresh();
    expect($conversation->memory_enabled)->toBeTrue();
});

it('createSummarySnapshot creates ConversationSummary record', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
    ]);

    $agent = ChatAgent::make();
    $snapshot = $this->service->createSummarySnapshot($conversation, $agent);

    expect($snapshot)->toBeInstanceOf(ConversationSummary::class)
        ->and($snapshot->conversation_id)->toBe($conversation->id)
        ->and($snapshot->message_count)->toBe(0)
        ->and($snapshot->summary)->toBe('');
});

it('summarizeConversation returns empty string for empty conversation', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
    ]);

    $agent = ChatAgent::make();
    $result = $this->service->summarizeConversation($conversation, $agent);

    expect($result)->toBe('');
});

it('summarizeConversation generates summary via ChatAgent for non-empty conversation', function (): void {
    $mockAgentHandler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $mockAgentHandler->shouldReceive('getMessage')
        ->andReturn(new NeuronAI\Chat\Messages\AssistantMessage('Conversation about deadlines'));

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')
        ->with(Mockery::type(NeuronAI\Chat\Messages\UserMessage::class))
        ->andReturn($mockAgentHandler);

    $service = new MemoryService(
        chatAgentFactory: fn (string $systemPrompt) => $mockAgent,
    );

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'When is the deadline?',
    ]);
    Message::query()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'The deadline is March 15.',
    ]);

    $result = $service->summarizeConversation($conversation);

    expect($result)->toBe('Conversation about deadlines');
});

it('summarizeConversation includes previous summary as context', function (): void {
    $mockAgentHandler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $mockAgentHandler->shouldReceive('getMessage')
        ->andReturn(new NeuronAI\Chat\Messages\AssistantMessage('Updated summary'));

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')
        ->with(Mockery::on(fn (NeuronAI\Chat\Messages\UserMessage $msg): bool => str_contains((string) $msg->getContent(), 'Previous summary:')
            && str_contains((string) $msg->getContent(), 'Old summary')))
        ->andReturn($mockAgentHandler);

    $service = new MemoryService(
        chatAgentFactory: fn (string $systemPrompt) => $mockAgent,
    );

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
        'summary' => 'Old summary',
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'New message here.',
    ]);

    $result = $service->summarizeConversation($conversation);

    expect($result)->toBe('Updated summary');
});

it('extractFacts returns empty array for empty conversation', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
    ]);

    $agent = ChatAgent::make();
    $result = $this->service->extractFacts($conversation, $agent);

    expect($result)->toBe([]);
});

it('extractFacts returns facts from agent response', function (): void {
    $mockAgentHandler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $mockAgentHandler->shouldReceive('getMessage')
        ->andReturn(new NeuronAI\Chat\Messages\AssistantMessage('["User prefers dark mode", "Deadline is March 15"]'));

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')->andReturn($mockAgentHandler);

    $service = new MemoryService(
        chatAgentFactory: fn (string $systemPrompt) => $mockAgent,
    );

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'I prefer dark mode.',
    ]);

    $result = $service->extractFacts($conversation);

    expect($result)->toBe(['User prefers dark mode', 'Deadline is March 15']);
});

it('extractFacts returns empty array on invalid json', function (): void {
    $mockAgentHandler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $mockAgentHandler->shouldReceive('getMessage')
        ->andReturn(new NeuronAI\Chat\Messages\AssistantMessage('not valid json'));

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')->andReturn($mockAgentHandler);

    $service = new MemoryService(
        chatAgentFactory: fn (string $systemPrompt) => $mockAgent,
    );

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Test message.',
    ]);

    $result = $service->extractFacts($conversation);

    expect($result)->toBe([]);
});

it('extractFacts returns empty array when json is not an array', function (): void {
    $mockAgentHandler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $mockAgentHandler->shouldReceive('getMessage')
        ->andReturn(new NeuronAI\Chat\Messages\AssistantMessage('"just a string"'));

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')->andReturn($mockAgentHandler);

    $service = new MemoryService(
        chatAgentFactory: fn (string $systemPrompt) => $mockAgent,
    );

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Test.',
    ]);

    $result = $service->extractFacts($conversation);

    expect($result)->toBe([]);
});

it('createSummarySnapshot updates conversation summary and creates record', function (): void {
    $mockAgentHandler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $mockAgentHandler->shouldReceive('getMessage')
        ->andReturn(new NeuronAI\Chat\Messages\AssistantMessage('Generated summary'));

    $factsHandler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $factsHandler->shouldReceive('getMessage')
        ->andReturn(new NeuronAI\Chat\Messages\AssistantMessage('["Fact one"]'));

    $callCount = 0;
    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')
        ->andReturnUsing(function () use (&$callCount, $mockAgentHandler, $factsHandler) {
            $callCount++;

            return $callCount === 1 ? $mockAgentHandler : $factsHandler;
        });

    $service = new MemoryService(
        chatAgentFactory: fn (string $systemPrompt) => $mockAgent,
    );

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
    ]);

    Message::query()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Hello there.',
    ]);

    $snapshot = $service->createSummarySnapshot($conversation);

    $conversation->refresh();
    expect($snapshot)->toBeInstanceOf(ConversationSummary::class)
        ->and($snapshot->summary)->toBe('Generated summary')
        ->and($conversation->summary)->toBe('Generated summary');
});
