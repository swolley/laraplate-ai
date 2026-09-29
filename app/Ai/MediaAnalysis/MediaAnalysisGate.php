<?php

declare(strict_types=1);

namespace Modules\AI\Ai\MediaAnalysis;

use function ai_config_bool;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Services\FeatureModuleGate;

/**
 * The runtime master switch for the whole media LLM analysis subsystem (M18), plus the
 * per-module allowlist (M8/M10). The switch is the seeded setting
 * `features.media_analysis.enabled` (group `ai`, off), read from config like every other AI
 * switch; it has no env variable. When off, no LLM media work runs and media still index on
 * Core's deterministic layer via the fallback listener (M12).
 */
final readonly class MediaAnalysisGate
{
    public function enabled(): bool
    {
        return ai_config_bool('ai.features.media_analysis.enabled', false);
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
