<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Contracts\IRagIndexRebuilder;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Search\Contracts\IProfileVectorIndex;
use Modules\Core\Search\Contracts\IReportsVectorDimensions;
use Modules\Core\Search\Contracts\ISearchEngine;
use Modules\Core\Search\Support\VectorModelContext;

/**
 * The index work of an embedding model switch: every embeddable model's index is filled again with
 * the target's vectors, and the documentation indexes are rebuilt for it.
 *
 * {@see self::prepare()} is the once-only part of the `indexes` phase, run by the switch job: on
 * pgvector the target's partial index is created, an index whose vectors already have the target's
 * dimensions keeps its mapping but is emptied (documents of records that are no longer searchable
 * must not survive), any other index is recreated, and the RAG indexes are rebuilt. The documents
 * themselves are written afterwards in chunks ({@see self::plan()}), each by an
 * `IndexDocumentsChunkJob` calling {@see self::writeChunk()}; the `verify` phase writes them all
 * once more the same way, without touching the indexes.
 *
 * Everything runs inside {@see VectorModelContext::using()} with the target key, so each document
 * carries only the target's vectors. The engines size the vector mapping from
 * `core.search.vector.dimensions` (and `similarity`) when they create an index, so both are set to
 * the target's for the duration of the work, in that process only, and restored afterwards.
 */
final readonly class EmbeddingSwitchIndexes
{
    /**
     * The config key of the number of records per chunk.
     */
    public const string CHUNK_SIZE_CONFIG = 'ai.features.embeddings.index_chunk_size';

    public const int DEFAULT_CHUNK_SIZE = 250;

    /**
     * The most records one chunk takes, whatever is configured: a chunk must fit in
     * {@see \Modules\AI\Jobs\IndexDocumentsChunkJob::TIMEOUT_SECONDS}.
     */
    public const int MAX_CHUNK_SIZE = 2000;

    public function __construct(
        private EmbeddingSwitchCorpus $corpus,
        private IRagIndexRebuilder $rag,
    ) {}

    /**
     * Creates the pgvector profile index, recreates or empties each embeddable model's index and
     * rebuilds the RAG indexes for the target; writes no document of the models.
     *
     * @param  int|null  $currentDimensions  The dimensions of an index whose engine cannot report them
     */
    public function prepare(EmbeddingModelProfile $target, ?int $currentDimensions): void
    {
        $this->ensureProfileIndex($target);

        $this->asTarget($target, function () use ($target, $currentDimensions): void {
            foreach ($this->corpus->models() as $modelClass) {
                $this->resetIndex($modelClass, $target, $currentDimensions);
            }

            $this->rag->rebuild($target);
        });
    }

    /**
     * The chunks that write every searchable document once: per embeddable model, ranges of
     * {@see self::chunkSize()} keys covering the whole key space, by an id unique in the plan.
     *
     * @return array<string, array{model: string, from: int|string|null, to: int|string|null}>
     */
    public function plan(): array
    {
        $chunks = [];

        foreach ($this->corpus->models() as $modelClass) {
            foreach ($this->corpus->keyRanges($modelClass, $this->chunkSize()) as $index => $range) {
                $chunks["{$modelClass}#{$index}"] = ['model' => $modelClass, 'from' => $range['from'], 'to' => $range['to']];
            }
        }

        return $chunks;
    }

    /**
     * Writes the searchable documents of one chunk with the target's vectors, without touching the
     * index or its mapping. Writing a chunk again writes the same documents again.
     *
     * @param  array{model: string, from: int|string|null, to: int|string|null}  $chunk
     */
    public function writeChunk(EmbeddingModelProfile $target, array $chunk): void
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $chunk['model'];

        $this->asTarget($target, function () use ($modelClass, $target, $chunk): void {
            /** @var Model $instance */
            $instance = new $modelClass();
            $engine = $instance->searchableUsing();

            $this->corpus->eachSearchableChunk($modelClass, static function (Collection $records) use ($engine, $instance): void {
                $engine->update($instance->makeSearchableUsing($records));
            }, ['embeddings' => static function (Relation $query) use ($target): void {
                $query->where('model_key', $target->key);
            }], $chunk['from'], $chunk['to']);
        });
    }

    /**
     * The number of records per chunk, `ai.features.embeddings.index_chunk_size`: below 1 or not a
     * number falls back to {@see self::DEFAULT_CHUNK_SIZE}, above {@see self::MAX_CHUNK_SIZE} is capped.
     */
    public function chunkSize(): int
    {
        return self::configuredSize(self::CHUNK_SIZE_CONFIG, self::DEFAULT_CHUNK_SIZE, self::MAX_CHUNK_SIZE);
    }

    /**
     * The positive whole number configured under `$key`, capped at `$max`; `$default` when it is
     * missing, not a number or below 1.
     */
    private static function configuredSize(string $key, int $default, int $max): int
    {
        $configured = config($key);

        if (! is_numeric($configured) || (int) $configured < 1) {
            return $default;
        }

        return min((int) $configured, $max);
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
     * Recreates the index when its vectors have other dimensions, empties it otherwise: documents of
     * records that are no longer searchable must not survive.
     *
     * @param  class-string<Model>  $modelClass
     */
    private function resetIndex(string $modelClass, EmbeddingModelProfile $target, ?int $currentDimensions): void
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
    }
}
