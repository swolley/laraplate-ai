<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Scout\Engines\Engine;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Contracts\IRagIndexRebuilder;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Search\Contracts\IProfileVectorIndex;
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
 * target's dimensions keeps its mapping but is emptied before its documents are written again, so
 * documents of records that are no longer searchable cannot survive; any other index is recreated.
 * {@see self::refresh()} rewrites the documents only, for the `verify` phase.
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
        $this->ensureProfileIndex($target);

        $this->asTarget($target, function () use ($target, $currentDimensions): void {
            foreach ($this->corpus->models() as $modelClass) {
                $this->rebuildModel($modelClass, $target, $currentDimensions);
            }

            $this->rag->rebuild($target);
        });
    }

    /**
     * Writes every searchable document again with the target's vectors, without touching the index
     * or its mapping: the documents of records edited since the `indexes` phase were written by the
     * normal pipeline with the serving model's vectors.
     */
    public function refresh(EmbeddingModelProfile $target): void
    {
        $this->asTarget($target, function () use ($target): void {
            foreach ($this->corpus->models() as $modelClass) {
                /** @var Model $instance */
                $instance = new $modelClass();

                $this->writeDocuments($modelClass, $instance, $instance->searchableUsing(), $target);
            }
        });
    }

    /**
     * On pgvector the target's rows are searched through their own partial index: it is created
     * (idempotently) here, ahead of the activation that makes the target serve search.
     */
    private function ensureProfileIndex(EmbeddingModelProfile $target): void
    {
        $indexes = app(IProfileVectorIndex::class);
        $connection = new ModelEmbedding()->getConnection();

        if ($indexes->supports($connection)) {
            $indexes->ensure($connection, $target->key, $target->dimensions, $target->similarity);
        }
    }

    /**
     * Runs the callback with the target as the vector model of the documents and its dimensions and
     * similarity as the ones new mappings take, restoring both settings afterwards.
     *
     * @param  Closure(): void  $callback
     */
    private function asTarget(EmbeddingModelProfile $target, Closure $callback): void
    {
        VectorModelContext::using($target->key, static function () use ($target, $callback): void {
            $previous = [
                'core.search.vector.dimensions' => config('core.search.vector.dimensions'),
                'core.search.vector.similarity' => config('core.search.vector.similarity'),
            ];

            config([
                'core.search.vector.dimensions' => $target->dimensions,
                'core.search.vector.similarity' => $target->similarity,
            ]);

            try {
                $callback();
            } finally {
                config($previous);
            }
        });
    }

    /**
     * Recreates the index when its vectors have other dimensions, empties it otherwise (documents of
     * records that are no longer searchable must not survive), then writes every searchable document.
     *
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
        } else {
            $engine->flush($instance);
        }

        $this->writeDocuments($modelClass, $instance, $engine, $target);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function writeDocuments(string $modelClass, Model $instance, Engine $engine, EmbeddingModelProfile $target): void
    {
        $this->corpus->eachSearchableChunk($modelClass, static function (Collection $chunk) use ($engine, $instance): void {
            $engine->update($instance->makeSearchableUsing($chunk));
        }, ['embeddings' => static function (Relation $query) use ($target): void {
            $query->where('model_key', $target->key);
        }]);
    }
}
