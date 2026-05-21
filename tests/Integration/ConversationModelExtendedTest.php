<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\ConversationSummary;
use Modules\AI\Models\Message;
use Modules\Core\Models\User;
use NeuronAI\Chat\Messages\Message as NeuronMessage;

uses(RefreshDatabase::class);

it('summaries returns HasMany relation', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);
    ConversationSummary::query()->create([
        'conversation_id' => $conversation->id,
        'summary' => 'First summary',
        'message_count' => 3,
    ]);
    ConversationSummary::query()->create([
        'conversation_id' => $conversation->id,
        'summary' => 'Second summary',
        'message_count' => 5,
    ]);

    expect($conversation->summaries)->toHaveCount(2)
        ->and($conversation->summaries->first())->toBeInstanceOf(ConversationSummary::class);
});

it('addMessage creates and returns a Message', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);

    $message = $conversation->addMessage('user', 'Hello world');

    expect($message)->toBeInstanceOf(Message::class)
        ->and($message->role)->toBe('user')
        ->and($message->content)->toBe('Hello world')
        ->and($message->conversation_id)->toBe($conversation->id);
});

it('addMessage stores metadata when provided', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);

    $message = $conversation->addMessage('assistant', 'Response', ['token_count' => 10]);

    expect($message->metadata)->toBe(['token_count' => 10]);
});

it('getMessagesForNeuron maps system role to USER by default', function (): void {
    $conversation = new Conversation;
    $msg = new Message(['role' => 'system', 'content' => 'System message']);
    $conversation->setRelation('messages', collect([$msg]));

    $neuron_messages = $conversation->getMessagesForNeuron();

    expect($neuron_messages)->toHaveCount(1)
        ->and($neuron_messages[0])->toBeInstanceOf(NeuronMessage::class)
        ->and($neuron_messages[0]->getRole())->toBe('user');
});

it('casts metadata and memory_enabled correctly', function (): void {
    $conversation = new Conversation;

    expect($conversation->getCasts())->toMatchArray([
        'metadata' => 'array',
        'memory_enabled' => 'boolean',
    ]);
});

it('defines user relationship', function (): void {
    $conversation = Conversation::query()->create([
        'user_id' => User::factory()->create()->id,
    ]);
    $relation = $conversation->user();
    expect($relation)->toBeInstanceOf(Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});
