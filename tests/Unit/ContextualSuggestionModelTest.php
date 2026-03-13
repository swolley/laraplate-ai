<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\ContextualSuggestion;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

it('has fillable attributes', function (): void {
    $suggestion = new ContextualSuggestion;

    expect($suggestion->getFillable())->toBe([
        'user_id',
        'context',
        'suggestion',
        'dismissed_at',
    ]);
});

it('casts context dismissed_at and created_at correctly', function (): void {
    $suggestion = new ContextualSuggestion;

    expect($suggestion->getCasts())->toMatchArray([
        'context' => 'array',
        'dismissed_at' => 'datetime',
        'created_at' => 'datetime',
    ]);
});

it('updates dismissed_at when dismissed', function (): void {
    $user = User::factory()->create();
    $suggestion = ContextualSuggestion::query()->create([
        'user_id' => $user->id,
        'suggestion' => 'Test suggestion',
    ]);

    expect($suggestion->dismissed_at)->toBeNull();

    $suggestion->dismiss();

    $suggestion->refresh();
    expect($suggestion->dismissed_at)->not->toBeNull();
});

it('belongs to user', function (): void {
    $user = User::factory()->create();
    $suggestion = ContextualSuggestion::query()->create([
        'user_id' => $user->id,
        'suggestion' => 'Test suggestion',
    ]);

    expect($suggestion->user)->toBeInstanceOf(User::class)
        ->and($suggestion->user->id)->toBe($user->id);
});

it('filters by forUser scope', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    ContextualSuggestion::query()->create([
        'user_id' => $user1->id,
        'suggestion' => 'Suggestion 1',
    ]);
    ContextualSuggestion::query()->create([
        'user_id' => $user2->id,
        'suggestion' => 'Suggestion 2',
    ]);

    expect(ContextualSuggestion::forUser($user1->id)->count())->toBe(1)
        ->and(ContextualSuggestion::forUser($user2->id)->count())->toBe(1);
});

it('filters by notDismissed scope', function (): void {
    $user = User::factory()->create();
    ContextualSuggestion::query()->create([
        'user_id' => $user->id,
        'suggestion' => 'Not dismissed',
    ]);
    ContextualSuggestion::query()->create([
        'user_id' => $user->id,
        'suggestion' => 'Dismissed',
        'dismissed_at' => now(),
    ]);

    expect(ContextualSuggestion::notDismissed()->count())->toBe(1);
});

it('filters by recent scope', function (): void {
    $user = User::factory()->create();
    ContextualSuggestion::query()->create([
        'user_id' => $user->id,
        'suggestion' => 'Recent',
    ]);

    expect(ContextualSuggestion::recent(60)->count())->toBeGreaterThanOrEqual(1);
});
