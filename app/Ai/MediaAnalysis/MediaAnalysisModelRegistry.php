<?php

declare(strict_types=1);

namespace Modules\AI\Ai\MediaAnalysis;

use function ai_config_string;

use InvalidArgumentException;

/**
 * Resolves media-analysis model profiles from
 * `ai.features.media_analysis.capabilities.{capability}`.
 *
 * Each capability (`vision`, `transcription`) declares an `active` profile key
 * and a `models` map of candidate profiles. This mirrors
 * {@see \Modules\AI\Ai\Embeddings\EmbeddingModelRegistry} so model/provider
 * choice stays an operator config decision rather than a code constant (M21).
 */
final class MediaAnalysisModelRegistry
{
    /**
     * Resolve the active profile for a capability.
     */
    public function active(string $capability): MediaAnalysisModelProfile
    {
        $active = ai_config_string("ai.features.media_analysis.capabilities.{$capability}.active", '');

        if ($active === '') {
            throw new InvalidArgumentException("No active media-analysis model configured for capability: {$capability}");
        }

        return $this->get($capability, $active);
    }

    /**
     * Resolve a specific profile within a capability.
     */
    public function get(string $capability, string $key): MediaAnalysisModelProfile
    {
        /** @var array<string, array<string, mixed>> $models */
        $models = (array) config("ai.features.media_analysis.capabilities.{$capability}.models", []);

        if ($models === []) {
            throw new InvalidArgumentException("Unknown media-analysis capability: {$capability}");
        }

        if (! array_key_exists($key, $models)) {
            throw new InvalidArgumentException("Unknown media-analysis model profile '{$key}' for capability: {$capability}");
        }

        $model = $models[$key];

        return new MediaAnalysisModelProfile(
            capability: $capability,
            key: $key,
            provider: self::stringValue($model['provider'] ?? null),
            serviceModel: self::stringValue($model['service_model'] ?? null),
        );
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
