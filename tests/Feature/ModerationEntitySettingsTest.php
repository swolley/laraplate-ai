<?php

declare(strict_types=1);

use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\AI\Services\ModerationEntitySettings;
use Modules\AI\Tests\Stubs\Moderation\ModeratedTestModel;
use Modules\AI\Tests\Stubs\Moderation\ModeratedTestModelAdapter;
use Modules\Core\Models\Setting;
use Modules\Core\Services\ModerationAdapterRegistry;

it('declares one disabled switch per model with a registered moderation adapter', function (): void {
    $registry = new ModerationAdapterRegistry();
    $registry->register(new ModeratedTestModelAdapter());

    $definitions = (new ModerationEntitySettings($registry))->definitions();

    expect($definitions)->toHaveCount(1)
        ->and($definitions[0]['name'])->toBe('features.moderation.entities.' . (new ModeratedTestModel())->getTable())
        ->and($definitions[0]['value'])->toBeFalse()
        ->and($definitions[0]['group_name'])->toBe('ai');
});

it('declares nothing when no moderation adapter is registered', function (): void {
    expect((new ModerationEntitySettings(new ModerationAdapterRegistry()))->definitions())->toBe([]);
});

it('seeds the moderation switches owned by the AI module', function (): void {
    app()->instance(ModerationAdapterRegistry::class, tap(new ModerationAdapterRegistry(), function (ModerationAdapterRegistry $registry): void {
        $registry->register(new ModeratedTestModelAdapter());
    }));

    $this->seed(AIDatabaseSeeder::class);

    $setting = Setting::query()->withoutGlobalScopes()
        ->where('name', ModerationEntitySettings::nameFor(new ModeratedTestModel()))
        ->sole();

    expect($setting->value)->toBeFalse()
        ->and($setting->module)->toBe('AI');
});

it('reads the switch from the runtime config the settings overlay fills', function (): void {
    config([ModerationEntitySettings::configKeyFor(new ModeratedTestModel()) => true]);

    expect(ModerationEntitySettings::enabledFor(new ModeratedTestModel()))->toBeTrue();
});
