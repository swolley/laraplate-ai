<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use Modules\AI\Contracts\IEmbeddableModels;
use Override;

/**
 * Discovers the models the embeddings pipeline handles: searchable, embeddable (vector search on
 * and an `$embed` list) and allowed by the per-module allowlist, the same predicates the indexing
 * listener applies, so a repair covers exactly what indexing would have embedded.
 */
final readonly class EmbeddableModels implements IEmbeddableModels
{
    /**
     * @return list<class-string<Model>>
     */
    #[Override]
    public function all(): array
    {
        $embeddable = [];

        foreach (models(false, filter: static fn (string $model): bool => class_uses_trait($model, Searchable::class)) as $class) {
            $instance = new $class();

            if (method_exists($instance, 'isEmbeddable') && $instance->isEmbeddable() && FeatureModuleGate::allows('embeddings', $instance)) {
                $embeddable[] = $class;
            }
        }

        return $embeddable;
    }
}
