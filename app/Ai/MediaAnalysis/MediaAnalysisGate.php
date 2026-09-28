<?php

declare(strict_types=1);

namespace Modules\AI\Ai\MediaAnalysis;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Services\FeatureModuleGate;
use Modules\Core\Services\PerModelSettingResolver;

/**
 * The runtime master switch for the whole media LLM analysis subsystem (M18),
 * plus the per-module allowlist (M8/M10). {@see enabled()} reads the Settings
 * table (name `media_analysis.enabled`, group `ai`) so operators can toggle the
 * subsystem at runtime without a deploy; when no row exists it falls back to the
 * static config default. When off, no LLM media work runs and media still index
 * on Core's deterministic layer via the fallback listener (M12).
 */
final readonly class MediaAnalysisGate
{
    public const string SETTING_NAME = 'media_analysis.enabled';

    public const string SETTING_GROUP = 'ai';

    public function __construct(private PerModelSettingResolver $settings) {}

    /**
     * The runtime master switch: the Settings row wins; otherwise the config default.
     */
    public function enabled(): bool
    {
        return $this->settings->boolean(
            self::SETTING_NAME,
            default: (bool) config('ai.features.media_analysis.enabled', false),
        );
    }

    /**
     * Whether media analysis may run for this model: master switch on and the
     * optional per-module allowlist admits the model.
     */
    public function allows(Model $model): bool
    {
        return $this->enabled() && FeatureModuleGate::allows('media_analysis', $model);
    }
}
