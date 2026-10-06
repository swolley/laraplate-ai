<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

it('has correct fillable attributes', function (): void {
    $message = new Message;

    expect($message->getFillable())->toBe([
        'conversation_id',
        'role',
        'content',
        'metadata',
        'token_count',
    ]);
});

it('casts metadata and token_count correctly', function (): void {
    $message = new Message;

    expect($message->getCasts())->toMatchArray([
        'metadata' => 'array',
        'token_count' => 'integer',
    ]);
});

it('belongs to conversation', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);
    $message = $conversation->addMessage('user', 'Test');

    expect($message->conversation)->toBeInstanceOf(Conversation::class)
        ->and($message->conversation->id)->toBe($conversation->id);
});
