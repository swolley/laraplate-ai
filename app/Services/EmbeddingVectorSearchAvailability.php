<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Search\Contracts\IVectorSearchAvailability;
use Modules\Core\Search\DTOs\VectorAvailability;
use Override;

/**
 * Decorates Core's guard: after Core's checks pass, vector search is only available when at least
 * one embedding stamped with the active profile exists.
 */
final readonly class EmbeddingVectorSearchAvailability implements IVectorSearchAvailability
{
    public function __construct(
        private IVectorSearchAvailability $inner,
        private EmbeddingModelRegistry $registry,
    ) {}

    #[Override]
    public function check(Model $model): VectorAvailability
    {
        $answer = $this->inner->check($model);

        if (! $answer->available) {
            return $answer;
        }

        try {
            $key = $this->registry->active()->key;
        } catch (InvalidArgumentException) {
            return VectorAvailability::no('no_vectors');
        }

        $exists = ModelEmbedding::query()->where('model_key', $key)->exists();

        return $exists ? $answer : VectorAvailability::no('no_vectors');
    }
}
