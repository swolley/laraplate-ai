<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use InvalidArgumentException;

/**
 * Resolves embedding-model profiles from `ai.features.embeddings.models`.
 * `dimensions`/`similarity` always derive from Core's `search.vector.*`
 * config rather than the per-model block, so the vector index and the
 * active model can never drift apart.
 */
final class EmbeddingModelRegistry
{
    public function active(): EmbeddingModelProfile
    {
        return $this->get((string) config('ai.features.embeddings.active', 'multilingual-e5-small'));
    }

    public function get(string $key): EmbeddingModelProfile
    {
        /** @var array<string, array<string, mixed>> $models */
        $models = (array) config('ai.features.embeddings.models', []);

        if (! array_key_exists($key, $models)) {
            throw new InvalidArgumentException("Unknown embedding model profile: {$key}");
        }

        $model = $models[$key];

        return new EmbeddingModelProfile(
            key: $key,
            provider: (string) ($model['provider'] ?? ''),
            serviceModel: (string) ($model['service_model'] ?? ''),
            dimensions: (int) config('search.vector.dimensions', 384),
            queryPrefix: (string) ($model['query_prefix'] ?? ''),
            passagePrefix: (string) ($model['passage_prefix'] ?? ''),
            similarity: (string) config('search.vector.similarity', 'cosine'),
            normalize: (bool) ($model['normalize'] ?? false),
        );
    }
}
