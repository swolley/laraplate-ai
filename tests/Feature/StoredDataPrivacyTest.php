<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Modules\AI\Models\ContextualSuggestion;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\ConversationSummary;
use Modules\AI\Models\Message;
use Modules\AI\Services\ContextualSuggestionService;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function privacySuggestion(User $user, array $overrides = []): ContextualSuggestion
{
    $createdAt = $overrides['created_at'] ?? null;
    unset($overrides['created_at']);

    $suggestion = ContextualSuggestion::query()->create([
        'user_id' => $user->id,
        'context' => ['page' => 'home'],
        'suggestion' => 'A suggestion',
        ...$overrides,
    ]);

    if ($createdAt !== null) {
        $suggestion->forceFill(['created_at' => $createdAt])->saveQuietly();
    }

    return $suggestion;
}

/**
 * A conversation with everything the assistant keeps about it: messages with a proposal, a summary
 * snapshot, and a title, a rolling summary and a system message on its own row.
 */
function privacyConversationWithProposal(User $user): Conversation
{
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'title' => 'Lists on my phone',
        'system_message' => 'Answer briefly.',
        'metadata' => ['source' => 'drawer'],
        'summary' => 'The user wants easier lists.',
    ]);
    $conversation->addMessage('user', 'Make my lists easier to read.');
    $conversation->addMessage('assistant', 'I suggest cards; accept it below.', [
        'citations' => [],
        'proposals' => [['id' => 'a', 'kind' => 'preference', 'target' => ['namespace' => 'ui', 'key' => 'defaultListLayout'], 'proposed' => 'cards', 'reason' => 'It suits you.']],
    ]);
    ConversationSummary::query()->create(['conversation_id' => $conversation->id, 'summary' => 'Lists on a phone.', 'facts' => ['device' => 'phone'], 'message_count' => 2]);

    return $conversation;
}

function privacyHoldsNothing(Conversation $conversation): bool
{
    $row = Conversation::query()->withTrashed()->find($conversation->id);

    return ! Message::query()->withTrashed()->where('conversation_id', $conversation->id)->exists()
        && ! ConversationSummary::query()->withTrashed()->where('conversation_id', $conversation->id)->exists()
        && ($row === null || ($row->title === null && $row->summary === null && $row->system_message === null && $row->metadata === null));
}

function privacyHoldsEverything(Conversation $conversation): bool
{
    $row = Conversation::query()->withTrashed()->find($conversation->id);

    return Message::query()->where('conversation_id', $conversation->id)->count() === 2
        && ConversationSummary::query()->where('conversation_id', $conversation->id)->count() === 1
        && $row->title === 'Lists on my phone' && $row->summary === 'The user wants easier lists.';
}

beforeEach(function (): void {
    Cache::flush();
    $this->user = User::factory()->create();
});

it('keeps only the page and the action of the context, and never what the client sent as data', function (): void {
    config()->set('ai.features.contextual_suggestions.enabled', true);
    $context = ['page' => 'settings', 'action' => 'edit', 'data' => ['order' => 42, 'customer' => 'Mario Rossi']];
    Cache::put('ai:suggestion:cache:' . $this->user->id . ':' . md5(json_encode($context, JSON_THROW_ON_ERROR)), 'Use the shortcuts', 3600);

    $suggestion = (new ContextualSuggestionService)->generateSuggestion($this->user, $context);

    expect($suggestion->context)->toBe(['page' => 'settings', 'action' => 'edit'])
        ->and(ContextualSuggestion::query()->find($suggestion->id)->context)->toBe(['page' => 'settings', 'action' => 'edit'])
        ->and(json_encode(ContextualSuggestion::query()->find($suggestion->id)->getAttributes()))->not->toContain('Mario Rossi');
});

it('retains nothing of a context that carries neither a page nor an action', function (array $context, array $kept): void {
    expect(ContextualSuggestion::retainedContext($context))->toBe($kept);
})->with([
    'data only' => [['data' => ['a' => 1]], []],
    'empty' => [[], []],
    'not text' => [['page' => ['x'], 'action' => 12], []],
    'an unknown key' => [['page' => 'home', 'selection' => 'secret'], ['page' => 'home']],
    'a long page is cut' => [['page' => str_repeat('p', 300)], ['page' => str_repeat('p', 255)]],
]);

it('prunes the suggestions older than the retention, soft deleted ones included, and keeps the others', function (): void {
    $old = privacySuggestion($this->user, ['created_at' => now()->subDays(ContextualSuggestion::RETENTION_DAYS + 1)]);
    $oldTrashed = privacySuggestion($this->user, ['created_at' => now()->subDays(ContextualSuggestion::RETENTION_DAYS + 3)]);
    $oldTrashed->delete();
    $recent = privacySuggestion($this->user, ['created_at' => now()->subDays(ContextualSuggestion::RETENTION_DAYS - 1)]);
    $fresh = privacySuggestion($this->user);

    Artisan::call('model:prune', ['--model' => [ContextualSuggestion::class]]);

    $remaining = ContextualSuggestion::query()->withTrashed()->pluck('id')->all();

    expect($remaining)->toContain($recent->id, $fresh->id)->not->toContain($old->id, $oldTrashed->id);
});

it('schedules the purge every day', function (): void {
    $events = collect(app(Schedule::class)->events())->filter(
        static fn (Event $event): bool => str_contains($event->command, 'model:prune') && str_contains($event->command, ContextualSuggestion::class),
    );

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 0 * * *');
});

it('removes the suggestions of a user who is deleted, soft or for good, and only theirs', function (string $how): void {
    $other = User::factory()->create();
    $mine = privacySuggestion($this->user);
    $mineDismissed = privacySuggestion($this->user);
    $mineDismissed->delete();
    $theirs = privacySuggestion($other);

    $how === 'soft' ? $this->user->delete() : $this->user->forceDelete();

    expect(ContextualSuggestion::query()->withTrashed()->pluck('id')->all())->toBe([$theirs->id])
        ->and(ContextualSuggestion::query()->withTrashed()->whereKey([$mine->id, $mineDismissed->id])->exists())->toBeFalse();
})->with(['soft delete' => 'soft', 'force delete' => 'force']);

it('removes what a conversation holds when it is deleted, and only that conversation', function (): void {
    $gone = privacyConversationWithProposal($this->user);
    $kept = privacyConversationWithProposal($this->user);

    expect(privacyHoldsEverything($gone))->toBeTrue();

    $gone->delete();

    expect(privacyHoldsNothing($gone))->toBeTrue()
        ->and(Conversation::query()->withTrashed()->find($gone->id)->trashed())->toBeTrue()
        ->and(privacyHoldsEverything($kept))->toBeTrue()
        ->and(Message::query()->where('conversation_id', $kept->id)->whereNotNull('metadata->proposals')->exists())->toBeTrue();
});

it('removes what a conversation holds when it is force deleted too', function (): void {
    $conversation = privacyConversationWithProposal($this->user);

    $conversation->forceDelete();

    expect(Message::query()->withTrashed()->where('conversation_id', $conversation->id)->exists())->toBeFalse()
        ->and(ConversationSummary::query()->withTrashed()->where('conversation_id', $conversation->id)->exists())->toBeFalse();
});

it('removes the conversations of a user who is deleted, with what they hold, and only theirs', function (string $how): void {
    $other = User::factory()->create();
    $active = privacyConversationWithProposal($this->user);
    $theirs = privacyConversationWithProposal($other);

    // A conversation deleted before the purge existed still holds its content.
    $legacy = privacyConversationWithProposal($this->user);
    $legacy->deleteQuietly();

    expect(privacyHoldsEverything($legacy))->toBeTrue();

    $how === 'soft' ? $this->user->delete() : $this->user->forceDelete();

    expect(privacyHoldsNothing($active))->toBeTrue()
        ->and(privacyHoldsNothing($legacy))->toBeTrue()
        ->and(privacyHoldsEverything($theirs))->toBeTrue();
})->with(['soft delete' => 'soft', 'force delete' => 'force']);
