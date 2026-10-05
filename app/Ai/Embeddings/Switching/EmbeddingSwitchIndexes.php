<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Contracts\IRagIndexRebuilder;
use Modules\Core\Search\Contracts\IReportsVectorDimensions;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\Support\VectorModelContext;

/**
 * The `indexes` phase of an embedding model switch: every embeddable model's index is filled again
 * with the target's vectors, and the documentation indexes are rebuilt for it.
 *
 * It runs inside {@see VectorModelContext::using()} with the target key, so each document carries
 * only the target's vectors. The engines size the vector mapping from `core.search.vector.dimensions`
 * (and `similarity`) when they create an index, so both are set to the target's for the duration of
 * the phase, in this process only, and restored afterwards. An index whose vectors already have the
 * target's dimensions keeps its mapping and only has its documents written again; any other index is
 * recreated first.
 */
final readonly class EmbeddingSwitchIndexes
{
    public function __construct(
        private EmbeddingSwitchCorpus $corpus,
        private IRagIndexRebuilder $rag,
    ) {}

    /**
     * @param  int|null  $currentDimensions  The dimensions of an index whose engine cannot report them
     */
    public function rebuild(EmbeddingModelProfile $target, ?int $currentDimensions): void
    {
        VectorModelContext::using($target->key, function () use ($target, $currentDimensions): void {
            $previous = [
                'core.search.vector.dimensions' => config('core.search.vector.dimensions'),
                'core.search.vector.similarity' => config('core.search.vector.similarity'),
            ];

            config([
                'core.search.vector.dimensions' => $target->dimensions,
                'core.search.vector.similarity' => $target->similarity,
            ]);

            try {
                foreach ($this->corpus->models() as $modelClass) {
                    $this->rebuildModel($modelClass, $target, $currentDimensions);
                }

                $this->rag->rebuild($target);
            } finally {
                config($previous);
            }
        });
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function rebuildModel(string $modelClass, EmbeddingModelProfile $target, ?int $currentDimensions): void
    {
        /** @var Model $instance */
        $instance = new $modelClass();
        $engine = $instance->searchableUsing();

        $indexed = $engine instanceof IReportsVectorDimensions
            ? $engine->indexedVectorDimensions($instance)
            : $currentDimensions;

        if ($engine instanceof ISearchEngine && $indexed !== $target->dimensions) {
            $engine->createIndex($instance, [], true);
        }

        $this->corpus->eachSearchableChunk($modelClass, static function (Collection $chunk) use ($engine, $instance): void {
            $engine->update($instance->makeSearchableUsing($chunk));
        }, ['embeddings' => static function (Relation $query) use ($target): void {
            $query->where('model_key', $target->key);
        }]);
    }
}
