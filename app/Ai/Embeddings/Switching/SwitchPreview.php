<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

/**
 * What changing the embedding model would cost, as the confirmation shows it before a switch.
 */
final readonly class SwitchPreview
{
    /**
     * @param  int|null  $currentDimensions  null when the active profile cannot be resolved
     * @param  int|null  $estimatedSeconds  a rough estimate, null when the probe latency cannot be measured
     */
    public function __construct(
        public string $currentModel,
        public string $targetModel,
        public ?int $currentDimensions,
        public int $targetDimensions,
        public bool $dimensionsDiffer,
        public int $recordsToEmbed,
        public ?int $estimatedSeconds,
    ) {}
}
