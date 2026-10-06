<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\Core\Models\Setting;

it('reads idle before anything is written and persists through the managed setting', function (): void {
    $this->seed(AIDatabaseSeeder::class);
    $store = new EmbeddingSwitchStore;

    expect($store->get()->status)->toBe('idle');

    $state = new EmbeddingSwitchState('running', 'preflight', 'a:b', 'c:d', 5, 1, null, '2026-10-05T10:00:00+00:00');
    $store->put($state);

    $stored = Setting::query()->withoutGlobalScopes()->where('name', 'features.embeddings.switch')->sole();

    expect(EmbeddingSwitchState::fromJson((string) $stored->value))->toEqual($state)
        ->and($store->get())->toEqual($state);
});

it('refuses a second lock holder and accepts one after release', function (): void {
    $store = new EmbeddingSwitchStore;

    $release = $store->lock();

    expect($release)->toBeInstanceOf(Closure::class)
        ->and($store->lock())->toBeNull();

    $release();

    $again = $store->lock();
    expect($again)->toBeInstanceOf(Closure::class);
    $again();
});

it('lets the start lock expire after two minutes', function (): void {
    $store = new EmbeddingSwitchStore;

    expect($store->lock())->toBeInstanceOf(Closure::class);

    $this->travel(119)->seconds();
    expect($store->lock())->toBeNull();

    $this->travel(2)->seconds();
    $again = $store->lock();
    expect($again)->toBeInstanceOf(Closure::class);
    $again();
});

/**
 * Creates `search.vector.suspended_reason` as the seeder does: managed, with a JSON null (core_settings.value is NOT NULL).
 */
function switch_store_seed_suspended_reason(): Setting
{
    $row = Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'search.vector.suspended_reason',
        'module' => 'Core',
        'type' => 'string',
        'value' => 'unset',
        'choices' => null,
        'group_name' => 'search',
    ]);
    Setting::query()->withoutGlobalScopes()->whereKey($row->getKey())->toBase()->update(['value' => 'null', 'managed' => true]);
    config()->set('core.search.vector.suspended_reason', null);

    return $row;
}

it('suspends vector search and clears the suspension through the real settings row', function (): void {
    $row = switch_store_seed_suspended_reason();
    $store = new EmbeddingSwitchStore;
    $stored = static fn (): Setting => Setting::query()->withoutGlobalScopes()->whereKey($row->getKey())->sole();

    $store->suspend();

    expect($stored()->value)->toBe(EmbeddingSwitchStore::SUSPENDED_REASON)
        ->and(config('core.search.vector.suspended_reason'))->toBe('switching');

    $store->clearSuspension();

    expect($stored()->value)->toBeNull()
        ->and($stored()->getRawOriginal('value'))->toBe('null')
        ->and(config('core.search.vector.suspended_reason'))->toBeNull();
});

it('flushes the cleared suspension only once the surrounding transaction commits', function (): void {
    switch_store_seed_suspended_reason();
    $store = new EmbeddingSwitchStore;
    $store->suspend();
    $insideTransaction = null;

    new Setting()->getConnection()->transaction(static function () use ($store, &$insideTransaction): void {
        $store->clearSuspension();
        $insideTransaction = config('core.search.vector.suspended_reason');
    });

    expect($insideTransaction)->toBe('switching')
        ->and(config('core.search.vector.suspended_reason'))->toBeNull();
});

it('treats a missing suspended reason row as nothing to clear', function (): void {
    (new EmbeddingSwitchStore)->clearSuspension();

    expect(Setting::query()->withoutGlobalScopes()->where('name', 'search.vector.suspended_reason')->exists())->toBeFalse();
});

it('updates the state it reads under the state lock, and writes nothing when the change keeps it', function (): void {
    $this->seed(AIDatabaseSeeder::class);
    $store = new EmbeddingSwitchStore;
    $store->put(new EmbeddingSwitchState('running', 'indexes', 'a:b', 'c:d', chunksDone: 1));
    $updatedAt = Setting::query()->withoutGlobalScopes()->where('name', 'features.embeddings.switch')->value('updated_at');

    $unchanged = $store->update(static fn (EmbeddingSwitchState $state): EmbeddingSwitchState => $state);

    expect($unchanged->chunksDone)->toBe(1)
        ->and(Setting::query()->withoutGlobalScopes()->where('name', 'features.embeddings.switch')->value('updated_at'))->toEqual($updatedAt);

    $store->put($store->get()->with(chunksDone: 2));
    $next = $store->update(static fn (EmbeddingSwitchState $state): EmbeddingSwitchState => $state->with(chunksDone: $state->chunksDone + 1));

    expect($next->chunksDone)->toBe(3)
        ->and($store->get()->chunksDone)->toBe(3);
});
