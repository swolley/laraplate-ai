<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\ConversationSummary;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

it('has fillable attributes', function (): void {
    $summary = new ConversationSummary;

    expect($summary->getFillable())->toBe([
        'conversation_id',
        'summary',
        'facts',
        'message_count',
    ]);
});

it('casts facts message_count and created_at correctly', function (): void {
    $summary = new ConversationSummary;

    expect($summary->getCasts())->toMatchArray([
        'facts' => 'array',
        'message_count' => 'integer',
        'created_at' => 'datetime',
    ]);
});

it('belongs to conversation', function (): void {
    $user = User::factory()->create();
    $conversation = Conversation::query()->create(['user_id' => $user->id]);
    $summary = ConversationSummary::query()->create([
        'conversation_id' => $conversation->id,
        'summary' => 'Test summary',
        'message_count' => 5,
    ]);

    expect($summary->conversation)->toBeInstanceOf(Conversation::class)
        ->and($summary->conversation->id)->toBe($conversation->id);
});
