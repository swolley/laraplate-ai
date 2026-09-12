<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

/**
 * Immutable description of a single embedding model's attributes: which
 * provider serves it, its output dimensionality and similarity metric, and
 * the query/passage prefixes it expects (e.g. E5-family instruction
 * prefixes). Resolved from config by EmbeddingModelRegistry.
 */
final readonly class EmbeddingModelProfile
{
    public function __construct(
        public string $key,
        public string $provider,
        public string $serviceModel,
        public int $dimensions,
        public string $queryPrefix,
        public string $passagePrefix,
        public string $similarity,
        public bool $normalize,
    ) {}
}
