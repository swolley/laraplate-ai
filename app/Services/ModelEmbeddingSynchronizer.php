<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Models\ModelEmbedding;

/**
 * Generates and stores per-(model, locale) embeddings with skip-if-fresh
 * semantics: a locale whose text (content hash) and embedding model (model_key)
 * are both unchanged is kept, only changed or model-stale locales are replaced,
 * and locales that no longer exist are dropped. Extracted from
 * {@see \Modules\AI\Jobs\GenerateEmbeddingsJob} so the single-model job and the
 * bulk indexing path share one store implementation and cannot diverge.
 */
final readonly class ModelEmbeddingSynchronizer
{
    public function __construct(
        private IEmbeddingService $embeddingService,
        private EmbeddingModelRegistry $registry,
    ) {}

    /**
     * @param  iterable<Model>  $models
     * @param  bool  $announceCompletion  When true (per-model path) each stored
     *                                    model emits {@see ModelPreProcessingCompleted}
     *                                    so the finalize listener indexes it.
     *                                    The bulk path passes false because it
     *                                    writes the engine itself in one batch,
     *                                    and the completion event would trigger
     *                                    a redundant per-model index.
     * @param  bool  $reload  When true (per-model path) each model is reloaded with
     *                        fresh(), because a queued job may carry a stale snapshot.
     *                        The bulk path passes false: its models come straight from
     *                        the import query with embeddings (and translations)
     *                        eager-loaded, so reloading would re-query per model and
     *                        discard those eager loads.
     */
    public function sync(iterable $models, ?string $locale = null, bool $announceCompletion = true, bool $reload = true): void
    {
        $model_key = $this->registry->active()->key;
        $default_locale = (string) (config('app.locale') ?: 'en');

        // Pass 1: plan the stale (model, locale) work and collect every text
        // that must be embedded across all models.
        $plans = [];
        $texts = [];

        foreach ($models as $model) {
            $fresh = $reload ? ($model->fresh() ?? $model) : $model;

            if (! $this->isEmbeddable($fresh)) {
                continue;
            }

            $plan = $this->planModel($fresh, $locale, $model_key, $default_locale, $texts);

            if ($plan !== null) {
                $plans[] = $plan;
            }
        }

        // Pass 2: one batched (adaptive) embedding call for all stale texts.
        $embedded = $texts === [] ? [] : $this->embeddingService->embedDocumentsBatch($texts);

        // Pass 3: persist per model, from the shared batch result.
        foreach ($plans as $plan) {
            $this->writePlan($plan, $model_key, $embedded, $announceCompletion);
        }
    }

    /**
     * @param  list<string>  $texts
     * @return array{model: Model, locale: string|null, stale: list<array{row_locale: string|null, content_hash: string, text_index: int}>, processed_locales: list<string|null>}|null
     */
    private function planModel(Model $model, ?string $locale, string $model_key, string $default_locale, array &$texts): ?array
    {
        /** @phpstan-ignore method.notFound */
        $by_locale = $model->prepareDataToEmbedByLocale($locale);

        if ($by_locale === []) {
            return null;
        }

        $is_translated = class_uses_trait($model, HasTranslations::class);

        // Reuse the eager-loaded embeddings relation (bulk path) when present,
        // filtering in memory; only query per model when it is not loaded
        // (per-model path). The bulk path always passes $locale === null.
        if ($locale === null && $model->relationLoaded('embeddings')) {
            /** @var \Illuminate\Support\Collection<int, ModelEmbedding> $existing */
            $existing = $model->getRelation('embeddings');
        } else {
            $existing = ($locale === null
                /** @phpstan-ignore method.notFound */
                ? $model->embeddings()
                /** @phpstan-ignore method.notFound */
                : $model->embeddings()->forLocale($locale)
            )->get();
        }

        $stale = [];
        $processed_locales = [];

        foreach ($by_locale as $loc => $text) {
            $row_locale = ($loc === $default_locale && ! $is_translated) ? null : $loc;
            $processed_locales[] = $row_locale;
            $content_hash = hash('sha256', $text);

            $is_fresh = $existing->first(static fn (ModelEmbedding $row): bool => $row->locale === $row_locale
                && $row->model_key === $model_key
                && $row->content_hash === $content_hash) !== null;

            if ($is_fresh) {
                continue;
            }

            $stale[] = ['row_locale' => $row_locale, 'content_hash' => $content_hash, 'text_index' => count($texts)];
            $texts[] = $text;
        }

        return ['model' => $model, 'locale' => $locale, 'stale' => $stale, 'processed_locales' => $processed_locales, 'existing' => $existing];
    }

    /**
     * @param  array{model: Model, locale: string|null, stale: list<array{row_locale: string|null, content_hash: string, text_index: int}>, processed_locales: list<string|null>, existing: \Illuminate\Support\Collection<int, ModelEmbedding>}  $plan
     * @param  list<\NeuronAI\RAG\Document[]>  $embedded
     */
    private function writePlan(array $plan, string $model_key, array $embedded, bool $announceCompletion): void
    {
        $model = $plan['model'];

        try {
            foreach ($plan['stale'] as $item) {
                /** @phpstan-ignore method.notFound */
                $model->embeddings()->forLocale($item['row_locale'])->delete();

                foreach ($embedded[$item['text_index']] as $document) {
                    /** @phpstan-ignore method.notFound */
                    $model->embeddings()->create([
                        'embedding' => $document->embedding,
                        'locale' => $item['row_locale'],
                        'model_key' => $model_key,
                        'content_hash' => $item['content_hash'],
                    ]);
                }
            }

            if ($plan['locale'] === null) {
                // Orphan locales (present in DB but no longer in the plan) are found
                // from the pre-write snapshot: rows for stale locales are in
                // processed_locales and were already replaced above, so rejecting
                // processed locales leaves exactly the orphans to drop.
                $plan['existing']
                    ->reject(static fn (ModelEmbedding $row): bool => in_array($row->locale, $plan['processed_locales'], true))
                    ->each(static fn (ModelEmbedding $row) => $row->delete());
            }

            // The bulk path serializes each model right after this via
            // toSearchableArray(), which prefers the loaded relation: refresh it so
            // a re-embedded model is indexed with its new vectors, not the stale
            // snapshot planModel() read.
            if ($plan['stale'] !== [] && $model->relationLoaded('embeddings')) {
                /** @phpstan-ignore method.notFound */
                $model->load('embeddings');
            }

            if ($announceCompletion) {
                event(new ModelPreProcessingCompleted($model, 'embeddings'));
            }
        } catch (Exception $exception) {
            Log::error('Embedding generation failed for model: ' . $model::class, [
                'model_id' => $model->getKey(),
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception;
        }
    }

    private function isEmbeddable(Model $model): bool
    {
        return is_callable([$model, 'prepareDataToEmbed'])
            && is_callable([$model, 'embeddings']);
    }
}
