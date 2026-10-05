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
