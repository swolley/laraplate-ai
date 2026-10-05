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

it('suspends vector search and clears the suspension through the real settings row', function (): void {
    $row = Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'search.vector.suspended_reason',
        'module' => 'Core',
        'type' => 'string',
        'value' => 'unset',
        'choices' => null,
        'group_name' => 'search',
    ]);
    // Seeded as the seeder does it: a JSON null, since core_settings.value is NOT NULL.
    Setting::query()->withoutGlobalScopes()->whereKey($row->getKey())->toBase()->update(['value' => 'null', 'managed' => true]);
    config()->set('core.search.vector.suspended_reason', null);
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
