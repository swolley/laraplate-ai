<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\AI\Contracts\IEmbeddableModels;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Models\ModelEmbedding;

/**
 * The records an embedding model switch works on: for each embeddable model, the population
 * `scout:import` indexes (`makeAllSearchableQuery()`) less the records the index would skip
 * (`shouldBeSearchable()`). A record is embedded when it has a row stamped with the target
 * `model_key` for each locale it has embeddable text in; a record with no embeddable text has no
 * row to wait for.
 */
final readonly class EmbeddingSwitchCorpus
{
    private const int CHUNK = 200;

    public function __construct(private IEmbeddableModels $embeddableModels) {}

    /**
     * Readable references to records, as `Class #id`, at most ten of them.
     *
     * @param  list<Model>  $models
     */
    public static function describe(array $models): string
    {
        $references = array_map(static fn (Model $model): string => class_basename($model) . ' #' . $model->getKey(), array_slice($models, 0, 10));

        return implode(', ', $references) . (count($models) > 10 ? ', ...' : '');
    }

    /**
     * @return list<class-string<Model>>
     */
    public function models(): array
    {
        return $this->embeddableModels->all();
    }

    /**
     * Calls `$callback` with each chunk of the searchable records of `$modelClass`.
     *
     * @param  class-string<Model>  $modelClass
     * @param  Closure(Collection<int, Model>): void  $callback
     * @param  array<int|string, mixed>  $with  Relations to eager-load on each chunk
     */
    public function eachSearchableChunk(string $modelClass, Closure $callback, array $with = []): void
    {
        /** @var Model $instance */
        $instance = new $modelClass();

        $modelClass::makeAllSearchableQuery()
            ->with($with)
            ->chunkById(self::CHUNK, static function (Collection $chunk) use ($callback): void {
                $searchable = $chunk
                    ->filter(static fn (Model $model): bool => ! method_exists($model, 'shouldBeSearchable') || $model->shouldBeSearchable())
                    ->values();

                if ($searchable->isNotEmpty()) {
                    $callback($searchable);
                }
            }, $instance->getQualifiedKeyName(), $instance->getKeyName());
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function searchableCount(string $modelClass): int
    {
        $count = 0;

        $this->eachSearchableChunk($modelClass, static function (Collection $chunk) use (&$count): void {
            $count += $chunk->count();
        });

        return $count;
    }

    /**
     * How far the corpus (or one model of it) is embedded with `$modelKey`: the (record, locale)
     * pairs expected and those with a row of the key, and the records still missing one.
     *
     * @param  class-string<Model>|null  $modelClass
     * @return array{total: int, done: int, pending: list<Model>}
     */
    public function progress(string $modelKey, ?string $modelClass = null): array
    {
        $total = 0;
        $done = 0;
        $pending = [];

        foreach ($modelClass === null ? $this->models() : [$modelClass] as $class) {
            $with = ['embeddings' => static function (Relation $query) use ($modelKey): void {
                $query->where('model_key', $modelKey);
            }];

            if (class_uses_trait($class, HasTranslations::class)) {
                $with[] = 'translations';
            }

            $this->eachSearchableChunk($class, function (Collection $chunk) use (&$total, &$done, &$pending, $modelKey): void {
                foreach ($chunk as $model) {
                    $locales = $this->expectedLocales($model);

                    if ($locales === []) {
                        continue;
                    }

                    /** @var \Illuminate\Support\Collection<int, ModelEmbedding> $rows */
                    $rows = $model->getRelation('embeddings');
                    $embedded = array_filter($locales, static fn (?string $locale): bool => $rows->contains(
                        static fn (ModelEmbedding $row): bool => $row->model_key === $modelKey && $row->locale === $locale,
                    ));

                    $total += count($locales);
                    $done += count($embedded);

                    if (count($embedded) < count($locales)) {
                        $pending[] = $model;
                    }
                }
            }, $with);
        }

        return ['total' => $total, 'done' => $done, 'pending' => $pending];
    }

    /**
     * The `locale` values of the rows the record must have: one per locale with embeddable text, the
     * default locale of a non-translated model stored as null, as the embedding synchronizer does.
     *
     * @return list<string|null>
     */
    private function expectedLocales(Model $model): array
    {
        if (! is_callable([$model, 'prepareDataToEmbedByLocale']) || ! is_callable([$model, 'embeddings'])) {
            return [];
        }

        $translated = class_uses_trait($model, HasTranslations::class);
        $default = (string) (config('app.locale') ?: 'en');
        $locales = [];

        foreach (array_keys($model->prepareDataToEmbedByLocale()) as $locale) {
            $locale = (string) $locale;
            $locales[] = ($locale === $default && ! $translated) ? null : $locale;
        }

        return $locales;
    }
}
