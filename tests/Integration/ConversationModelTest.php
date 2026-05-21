<?php

declare(strict_types=1);

use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use NeuronAI\Chat\Messages\Message as NeuronMessage;

it('converts messages to NeuronAI format', function (): void {
    $conversation = new Conversation;

    $msg1 = new Message(['role' => 'user', 'content' => 'Hello']);
    $msg2 = new Message(['role' => 'assistant', 'content' => 'Hi there!']);
    $msg3 = new Message(['role' => 'user', 'content' => 'How are you?']);

    $conversation->setRelation('messages', collect([$msg1, $msg2, $msg3]));

    $neuron_messages = $conversation->getMessagesForNeuron();

    expect($neuron_messages)->toHaveCount(3)
        ->and($neuron_messages[0])->toBeInstanceOf(NeuronMessage::class)
        ->and($neuron_messages[0]->getRole())->toBe('user')
        ->and($neuron_messages[0]->getContent())->toBe('Hello')
        ->and($neuron_messages[1]->getRole())->toBe('assistant')
        ->and($neuron_messages[1]->getContent())->toBe('Hi there!')
        ->and($neuron_messages[2]->getRole())->toBe('user')
        ->and($neuron_messages[2]->getContent())->toBe('How are you?');
});

it('returns empty array when no messages exist', function (): void {
    $conversation = new Conversation;
    $conversation->setRelation('messages', collect());

    expect($conversation->getMessagesForNeuron())->toBeEmpty();
});

it('maps unknown roles to user by default', function (): void {
    $conversation = new Conversation;
    $msg = new Message(['role' => 'system', 'content' => 'System message']);
    $conversation->setRelation('messages', collect([$msg]));

    $neuron_messages = $conversation->getMessagesForNeuron();

    expect($neuron_messages[0]->getRole())->toBe('user');
});
