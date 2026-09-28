<?php

declare(strict_types=1);

use Modules\AI\Ai\MediaAnalysis\MediaAnalysisGate;
use Modules\Core\Models\Setting;
use Modules\Core\Services\PerModelSettingResolver;

it('falls back to the config default when no setting row exists', function (): void {
    config()->set('ai.features.media_analysis.enabled', false);
    expect(app(MediaAnalysisGate::class)->enabled())->toBeFalse();

    config()->set('ai.features.media_analysis.enabled', true);
    expect(app(MediaAnalysisGate::class)->enabled())->toBeTrue();
});

it('lets the runtime setting override the config default', function (): void {
    config()->set('ai.features.media_analysis.enabled', true);

    Setting::query()->create([
        'name' => MediaAnalysisGate::SETTING_NAME,
        'group_name' => MediaAnalysisGate::SETTING_GROUP,
        'description' => 'Master switch for media LLM analysis',
        'value' => false,
    ]);
    app(PerModelSettingResolver::class)->flush();

    expect(app(MediaAnalysisGate::class)->enabled())->toBeFalse();
});
