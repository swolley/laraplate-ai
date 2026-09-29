<?php

declare(strict_types=1);

use Modules\AI\Ai\MediaAnalysis\MediaAnalysisGate;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\Core\Models\Setting;

it('is off when nothing turns it on', function (): void {
    config()->set('ai.features.media_analysis.enabled', null);

    expect(app(MediaAnalysisGate::class)->enabled())->toBeFalse();
});

it('follows the seeded setting, which starts off', function (): void {
    $this->seed(AIDatabaseSeeder::class);

    $setting = Setting::query()->withoutGlobalScopes()->where('name', 'features.media_analysis.enabled')->sole();

    expect($setting->value)->toBeFalse()
        ->and($setting->group_name)->toBe('ai')
        ->and(app(MediaAnalysisGate::class)->enabled())->toBeFalse();

    $setting->value = true;
    $setting->save();

    expect(app(MediaAnalysisGate::class)->enabled())->toBeTrue();
});
