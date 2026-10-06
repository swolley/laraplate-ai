<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Models\ContextualSuggestion;
use Modules\AI\Services\ContextualSuggestionService;
use Modules\AI\Tests\Stubs\Users\UserWithFixedKey;
use Modules\Core\Models\User;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('ai.features.contextual_suggestions.enabled', false);
    Cache::flush();
});

it('returns null when feature disabled', function (): void {
    config()->set('ai.features.contextual_suggestions.enabled', false);

    $user = User::factory()->create();
    $service = new ContextualSuggestionService;

    expect($service->generateSuggestion($user, ['page' => 'dashboard']))->toBeNull();
});

it('returns null when rate limited', function (): void {
    config()->set('ai.features.contextual_suggestions.enabled', true);
    config()->set('ai.features.contextual_suggestions.cooldown_minutes', 5);

    $user = User::factory()->create();
    Cache::put('ai:suggestion:rate:' . $user->id, now(), 300);

    $service = new ContextualSuggestionService;

    expect($service->generateSuggestion($user, ['page' => 'dashboard']))->toBeNull();
});

it('returns cached suggestion if available', function (): void {
    config()->set('ai.features.contextual_suggestions.enabled', true);

    $user = User::factory()->create();
    $context = ['page' => 'settings', 'action' => 'edit'];
    $cacheKey = 'ai:suggestion:cache:' . $user->id . ':' . md5(json_encode($context, JSON_THROW_ON_ERROR));
    Cache::put($cacheKey, 'Use keyboard shortcuts for faster editing', 3600);

    $service = new ContextualSuggestionService;
    $result = $service->generateSuggestion($user, $context);

    expect($result)->toBeInstanceOf(ContextualSuggestion::class)
        ->and($result->suggestion)->toBe('Use keyboard shortcuts for faster editing')
        ->and($result->user_id)->toBe($user->id);
});

it('getPendingSuggestions returns collection from DB', function (): void {
    $user = User::factory()->create();
    ContextualSuggestion::query()->create([
        'user_id' => $user->id,
        'context' => ['page' => 'unit-test'],
        'suggestion' => 'Pending suggestion',
    ]);

    $service = new ContextualSuggestionService;
    $pending = $service->getPendingSuggestions($user);

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->suggestion)->toBe('Pending suggestion');
});

it('dismissSuggestion calls dismiss on the model', function (): void {
    $user = User::factory()->create();
    $suggestion = ContextualSuggestion::query()->create([
        'user_id' => $user->id,
        'context' => ['page' => 'unit-test'],
        'suggestion' => 'To dismiss',
    ]);

    $service = new ContextualSuggestionService;
    $service->dismissSuggestion($suggestion);

    $suggestion->refresh();
    expect($suggestion->dismissed_at)->not->toBeNull();
});

it('buildPromptFromContext builds correct prompts with page action data keys', function (): void {
    $service = new ContextualSuggestionService;
    $method = new ReflectionMethod($service, 'buildPromptFromContext');

    $result = $method->invoke($service, [
        'page' => 'Dashboard',
        'action' => 'View reports',
        'data' => ['id' => 1],
    ]);

    expect($result)->toContain('Current page: Dashboard')
        ->toContain('Current action: View reports')
        ->toContain('Context data:')
        ->toContain('{"id":1}')
        ->toContain('Provide a brief, helpful suggestion.');
});

it('buildPromptFromContext returns default for empty context', function (): void {
    $service = new ContextualSuggestionService;
    $method = new ReflectionMethod($service, 'buildPromptFromContext');

    $result = $method->invoke($service, []);

    expect($result)->toBe('Provide a helpful general suggestion for the user.');
});

it('buildPromptFromContext handles non-array data as string', function (): void {
    $service = new ContextualSuggestionService;
    $method = new ReflectionMethod($service, 'buildPromptFromContext');

    $result = $method->invoke($service, [
        'page' => 'Dashboard',
        'data' => 'plain string value',
    ]);

    expect($result)->toContain('Current page: Dashboard')
        ->toContain('Context data: plain string value')
        ->toContain('Provide a brief, helpful suggestion.');
});

it('isRateLimited returns true when within cooldown', function (): void {
    $service = new ContextualSuggestionService;
    $method = new ReflectionMethod($service, 'isRateLimited');

    $user = User::factory()->create();
    config()->set('ai.features.contextual_suggestions.cooldown_minutes', 10);
    Cache::put('ai:suggestion:rate:' . $user->id, now()->subMinutes(2), 600);

    $result = $method->invoke($service, $user);

    expect($result)->toBeTrue();
});

it('isRateLimited returns false once the cooldown has passed, even while the entry is still cached', function (): void {
    $service = new ContextualSuggestionService;
    $method = new ReflectionMethod($service, 'isRateLimited');

    $user = User::factory()->create();
    config()->set('ai.features.contextual_suggestions.cooldown_minutes', 5);
    Cache::put('ai:suggestion:rate:' . $user->id, now()->subMinutes(10), 3600);

    $result = $method->invoke($service, $user);

    expect($result)->toBeFalse();
});

it('updateRateLimit writes to cache', function (): void {
    $service = new ContextualSuggestionService;
    $method = new ReflectionMethod($service, 'updateRateLimit');

    $user = User::factory()->create();
    config()->set('ai.features.contextual_suggestions.cooldown_minutes', 5);

    $method->invoke($service, $user);

    expect(Cache::get('ai:suggestion:rate:' . $user->id))->not->toBeNull();
});

it('resolveUserId casts numeric string keys to integers', function (): void {
    $service = new ContextualSuggestionService;
    $method = new ReflectionMethod($service, 'resolveUserId');

    $user = UserWithFixedKey::keyed('42');

    expect($method->invoke($service, $user))->toBe(42);
});

it('resolveUserId falls back to zero for non numeric keys', function (): void {
    $service = new ContextualSuggestionService;
    $method = new ReflectionMethod($service, 'resolveUserId');

    $user = UserWithFixedKey::keyed(['compound']);

    expect($method->invoke($service, $user))->toBe(0);
});

/**
 * A suggestion service whose model is Neuron's fake provider, answering with the given replies in
 * order; with none, it fails like a provider that is down. What it was asked is read from $provider.
 *
 * @param  list<string>  $replies
 */
function suggestionServiceAnswering(array $replies, ?FakeAIProvider &$provider = null): ContextualSuggestionService
{
    $provider = new FakeAIProvider(...array_map(static fn (string $reply): AssistantMessage => new AssistantMessage($reply), $replies));
    $model = $provider;

    return new ContextualSuggestionService(chatAgentFactory: static fn (): ChatAgent => ChatAgent::make(systemPrompt: 'Suggest.')->setAiProvider($model));
}

it('generateSuggestion calls AI and creates suggestion when not cached', function (): void {
    config()->set('ai.features.contextual_suggestions.enabled', true);
    config()->set('ai.features.contextual_suggestions.cooldown_minutes', 0);
    config()->set('ai.features.contextual_suggestions.cache_ttl', 3600);

    $service = suggestionServiceAnswering(['Try using keyboard shortcuts'], $provider);

    $user = User::factory()->create();
    $result = $service->generateSuggestion($user, ['page' => 'settings']);

    expect($result)->toBeInstanceOf(ContextualSuggestion::class)
        ->and($result->suggestion)->toBe('Try using keyboard shortcuts')
        ->and($result->user_id)->toBe($user->id)
        ->and((string) $provider->getRecorded()[0]->messages[0]->getContent())->toContain('settings');
});

it('generateSuggestion returns null when AI returns empty text or fails', function (array $replies): void {
    config()->set('ai.features.contextual_suggestions.enabled', true);
    config()->set('ai.features.contextual_suggestions.cooldown_minutes', 0);

    $result = suggestionServiceAnswering($replies)->generateSuggestion(User::factory()->create(), ['page' => 'home']);

    expect($result)->toBeNull();
})->with([
    'empty text' => [['']],
    'the provider is down' => [[]],
]);
