<?php

declare(strict_types=1);

namespace Modules\AI\Ai\MediaAnalysis;

use InvalidArgumentException;
use Modules\AI\Ai\Providers\AiModelChoice;
use Modules\AI\Enums\AiModelFeature;

/**
 * Resolves the media-analysis profile of a capability from its model setting
 * (`features.media_analysis.{vision,transcription}.model`). The profile key is the full
 * `provider:model` choice, stored with each analysis as its model version.
 */
final class MediaAnalysisModelRegistry
{
    public function active(string $capability): MediaAnalysisModelProfile
    {
        $feature = match ($capability) {
            'vision' => AiModelFeature::Vision,
            'transcription' => AiModelFeature::Transcription,
            default => throw new InvalidArgumentException("Unknown media-analysis capability: {$capability}"),
        };

        $choice = AiModelChoice::forFeature($feature);

        return new MediaAnalysisModelProfile(
            capability: $capability,
            key: $choice->value(),
            provider: $choice->provider,
            serviceModel: $choice->model ?? '',
        );
    }
}
