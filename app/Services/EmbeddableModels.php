<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Contracts\IEmbeddableModels;
use Modules\Core\Search\Traits\Searchable;
use Override;

/**
 * Discovers the models the embeddings pipeline handles: searchable, embeddable (vector search on
 * and an `$embed` list) and allowed by the per-module allowlist, the same gate the indexing
 * listeners apply ({@see EmbeddingsGate::handles()}), so a repair covers exactly what indexing would have embedded.
 */
final readonly class EmbeddableModels implements IEmbeddableModels
{
    public function __construct(private EmbeddingsGate $gate) {}

    /**
     * @return list<class-string<Model>>
     */
    #[Override]
    public function all(): array
    {
        $embeddable = [];

        foreach (models(false, filter: static fn (string $model): bool => class_uses_trait($model, Searchable::class)) as $class) {
            $instance = new $class();

            if ($this->gate->handles($instance)) {
                $embeddable[] = $class;
            }
        }

        return $embeddable;
    }
}
