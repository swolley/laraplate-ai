<?php

declare(strict_types=1);

namespace Modules\AI\Ai\MediaAnalysis;

/**
 * Immutable description of a single media-analysis model: the capability it
 * serves (`vision` or `transcription`), which provider serves it, and the
 * concrete service model string. Resolved from config by
 * {@see MediaAnalysisModelRegistry}, mirroring the embeddings registry.
 */
final readonly class MediaAnalysisModelProfile
{
    public function __construct(
        public string $capability,
        public string $key,
        public string $provider,
        public string $serviceModel,
    ) {}
}
