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
    config()->set('ai.features.chat.summary.enabled', true);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => false,
    ]);

    expect($this->service->shouldSummarize($conversation))->toBeFalse();
});

it('shouldSummarize returns false when config disabled', function (): void {
    config()->set('ai.features.chat.summary.enabled', false);

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'memory_enabled' => true,
    ]);

    expect($this->service->shouldSummarize($conversation))->toBeFalse();
});

it('shouldSummarize returns true when message count >= threshold', function (): void {
    config()->set('ai.features.chat.summary.enabled', true);
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
    config()->set('ai.features.chat.summary.enabled', true);
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

    $snapshot = $this->service->createSummarySnapshot($conversation);

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

    $result = $this->service->summarizeConversation($conversation);

    expect($result)->toBe('');
});

it('summarizeConversation generates summary via ChatAgent for non-empty conversation', function (): void {
    $service = memoryServiceAnswering(['  Conversation about deadlines  '], $provider);

    $conversation = conversationWithAMessage('When is the deadline?');
    Message::query()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'The deadline is March 15.',
    ]);

    $result = $service->summarizeConversation($conversation);
    $prompt = (string) $provider->getRecorded()[0]->messages[0]->getContent();

    expect($result)->toBe('Conversation about deadlines')
        ->and($prompt)->toContain('User: When is the deadline?')->toContain('Assistant: The deadline is March 15.')->not->toContain('Previous summary:');
});

it('summarizeConversation includes previous summary as context', function (): void {
    $service = memoryServiceAnswering(['Updated summary'], $provider);

    $conversation = conversationWithAMessage('New message here.');
    $conversation->update(['summary' => 'Old summary']);

    $result = $service->summarizeConversation($conversation);
    $prompt = (string) $provider->getRecorded()[0]->messages[0]->getContent();

    expect($result)->toBe('Updated summary')
        ->and($prompt)->toContain('Previous summary:')->toContain('Old summary')->toContain('New message here.');
});

/**
 * A memory service whose model is Neuron's fake provider, answering with the given replies in order;
 * with none, it fails like a provider that is down. What it was asked is read from $provider.
 *
 * @param  list<string>  $replies
 */
function memoryServiceAnswering(array $replies, ?NeuronAI\Testing\FakeAIProvider &$provider = null): MemoryService
{
    $provider = new NeuronAI\Testing\FakeAIProvider(...array_map(static fn (string $reply): NeuronAI\Chat\Messages\AssistantMessage => new NeuronAI\Chat\Messages\AssistantMessage($reply), $replies));

    return new MemoryService(chatAgentFactory: static fn (string $system): ChatAgent => ChatAgent::make(systemPrompt: $system)->setAiProvider($provider));
}

function conversationWithAMessage(string $content = 'I prefer dark mode.'): Conversation
{
    $conversation = Conversation::query()->create(['user_id' => User::factory()->create()->id, 'memory_enabled' => true]);
    Message::query()->create(['conversation_id' => $conversation->id, 'role' => 'user', 'content' => $content]);

    return $conversation;
}

it('extractFacts returns the facts the model answers, without the empty ones', function (): void {
    $service = memoryServiceAnswering(['{"facts": ["User prefers dark mode", "", "Deadline is March 15"]}'], $provider);

    expect($service->extractFacts(conversationWithAMessage()))->toBe(['User prefers dark mode', 'Deadline is March 15'])
        ->and($provider->getRecorded()[0]->structuredClass)->toBe(Modules\AI\Data\ExtractedFacts::class)
        ->and((string) $provider->getRecorded()[0]->messages[0]->getContent())->toContain('I prefer dark mode.');
});

it('extractFacts asks again once when the first answer does not fit', function (): void {
    $service = memoryServiceAnswering(['Here are the facts: the user prefers dark mode.', '{"facts": ["User prefers dark mode"]}'], $provider);

    expect($service->extractFacts(conversationWithAMessage()))->toBe(['User prefers dark mode']);

    $provider->assertCallCount(2);
});

it('extractFacts returns an empty array when no answer fits or the provider fails', function (array $replies): void {
    expect(memoryServiceAnswering($replies)->extractFacts(conversationWithAMessage()))->toBe([]);
})->with([
    'invalid json twice' => [['not valid json', 'still not']],
    'a bare string' => [['"just a string"', '"again"']],
    'a bare list instead of the object' => [['["User prefers dark mode"]']],
    'the provider is down' => [[]],
]);

it('createSummarySnapshot updates conversation summary and creates record', function (): void {
    $service = memoryServiceAnswering(['Generated summary', '{"facts": ["Fact one"]}']);
    $conversation = conversationWithAMessage('Hello there.');

    $snapshot = $service->createSummarySnapshot($conversation);

    $conversation->refresh();
    expect($snapshot)->toBeInstanceOf(ConversationSummary::class)
        ->and($snapshot->summary)->toBe('Generated summary')
        ->and($snapshot->facts)->toBe(['Fact one'])
        ->and($conversation->summary)->toBe('Generated summary');
});
