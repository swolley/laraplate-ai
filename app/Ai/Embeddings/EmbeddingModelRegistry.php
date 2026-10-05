<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use Closure;
use InvalidArgumentException;

/**
 * Resolves embedding-model profiles from `ai.features.embeddings.models`.
 * A profile key is `provider:service_model`, split at the first colon, and the
 * profile declares its own `dimensions` and `similarity`. The active profile can
 * be overridden for the duration of a callback with `withActive()`.
 */
final class EmbeddingModelRegistry
{
    private const string DEFAULT_KEY = 'sentence_transformers:intfloat/multilingual-e5-small';

    private ?string $override = null;

    public function active(): EmbeddingModelProfile
    {
        return $this->get($this->override ?? (string) config('ai.features.embeddings.active', self::DEFAULT_KEY));
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys((array) config('ai.features.embeddings.models', [])));
    }

    /**
     * Runs the callback with `$key` as the active profile; the previous override is restored afterwards, also on failure.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withActive(string $key, Closure $callback): mixed
    {
        $previous = $this->override;
        $this->override = $key;

        try {
            return $callback();
        } finally {
            $this->override = $previous;
        }
    }

    public function get(string $key): EmbeddingModelProfile
    {
        /** @var array<string, array<string, mixed>> $models */
        $models = (array) config('ai.features.embeddings.models', []);

        if (! array_key_exists($key, $models)) {
            throw new InvalidArgumentException("Unknown embedding model profile: {$key}");
        }

        $model = $models[$key];
        $dimensions = (int) ($model['dimensions'] ?? 0);

        if ($dimensions < 1) {
            throw new InvalidArgumentException("Embedding model profile {$key} declares no valid dimensions");
        }

        [$provider, $serviceModel] = array_pad(explode(':', $key, 2), 2, '');

        return new EmbeddingModelProfile(
            key: $key,
            provider: $provider,
            serviceModel: $serviceModel,
            dimensions: $dimensions,
            queryPrefix: (string) ($model['query_prefix'] ?? ''),
            passagePrefix: (string) ($model['passage_prefix'] ?? ''),
            similarity: (string) ($model['similarity'] ?? 'cosine'),
            normalize: (bool) ($model['normalize'] ?? false),
        );
    }
}
