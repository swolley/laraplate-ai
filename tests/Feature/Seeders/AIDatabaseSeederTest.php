<?php

declare(strict_types=1);

use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\Core\Models\Setting;

it('seeds AI runtime settings stamped with the AI module', function (): void {
    $this->seed(AIDatabaseSeeder::class);

    $names = collect(AIDatabaseSeeder::runtimeSettingDefinitions())->pluck('name');

    $settings = Setting::query()->withoutGlobalScopes()->whereIn('name', $names)->get();

    expect($settings)->toHaveCount($names->count())
        ->and($settings->pluck('module')->unique()->all())->toBe(['AI']);
});

it('is idempotent and leaves an operator-changed value untouched on a second run', function (): void {
    $this->seed(AIDatabaseSeeder::class);

    Setting::query()->withoutGlobalScopes()
        ->where('name', 'ai.features.chat.max_context_messages')
        ->update(['value' => json_encode(999), 'description' => 'drifted']);

    $this->seed(AIDatabaseSeeder::class);

    $setting = Setting::query()->withoutGlobalScopes()
        ->where('name', 'ai.features.chat.max_context_messages')->sole();

    expect($setting->value)->toBe(999)
        ->and($setting->description)->toBe('Maximum chat context messages');
});
