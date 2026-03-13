<?php

declare(strict_types=1);

use Modules\AI\Models\Conversation;
use Modules\Core\Models\User;

beforeEach(function (): void {
    if (! app()->routesAreCached() && ! Illuminate\Support\Facades\Route::has('conversations.store')) {
        $this->markTestSkipped('Routes not available (standalone context).');
    }
});

test('it can create a conversation', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = $this->postJson('/app/crud/insert/conversations', [
        'title' => 'Test conversation',
        'system_message' => 'You are a helpful assistant.',
        'metadata' => ['foo' => 'bar'],
    ]);

    $response->assertCreated()
        ->assertJsonFragment([
            'user_id' => $user->id,
            'title' => 'Test conversation',
            'system_message' => 'You are a helpful assistant.',
        ]);

    $this->assertDatabaseHas('ai_conversations', [
        'user_id' => $user->id,
        'title' => 'Test conversation',
    ]);
});

test('it can send and store messages for a conversation', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $this->actingAs($user);

    /** @var Conversation $conversation */
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'title' => 'Chat with AI',
        'system_message' => 'You are a helpful assistant.',
        'metadata' => null,
    ]);

    // Simula l\'invio di un messaggio utente.
    $conversation->addMessage('user', 'Hello, how are you?', ['source' => 'test']);

    // Simula la risposta dell\'assistant (senza chiamare provider esterni).
    $conversation->addMessage('assistant', 'I am fine, thank you!');

    // Ci devono essere due messaggi: user e assistant.
    $this->assertDatabaseCount('ai_messages', 2);

    $this->assertDatabaseHas('ai_messages', [
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Hello, how are you?',
    ]);

    $this->assertDatabaseHas('ai_messages', [
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'I am fine, thank you!',
    ]);
});

test('it can list conversation messages', function (): void {
    /** @var User $user */
    $user = User::factory()->create();

    $this->actingAs($user);

    /** @var Conversation $conversation */
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'title' => 'Chat history',
        'system_message' => null,
        'metadata' => null,
    ]);

    $conversation->addMessage('user', 'First message');
    $conversation->addMessage('assistant', 'First reply');

    $response = $this->getJson("/app/crud/list/conversations/{$conversation->id}/messages");

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                [
                    'id',
                    'conversation_id',
                    'role',
                    'content',
                ],
            ],
        ]);
});
