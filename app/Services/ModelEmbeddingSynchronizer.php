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
     */
    public function sync(iterable $models, ?string $locale = null): void
    {
        $model_key = $this->registry->active()->key;
        $default_locale = (string) (config('app.locale') ?: 'en');

        // Pass 1: plan the stale (model, locale) work and collect every text
        // that must be embedded across all models.
        $plans = [];
        $texts = [];

        foreach ($models as $model) {
            $fresh = $model->fresh() ?? $model;

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
            $this->writePlan($plan, $model_key, $embedded);
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

        $existing = ($locale === null
            /** @phpstan-ignore method.notFound */
            ? $model->embeddings()
            /** @phpstan-ignore method.notFound */
            : $model->embeddings()->forLocale($locale)
        )->get();

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

        return ['model' => $model, 'locale' => $locale, 'stale' => $stale, 'processed_locales' => $processed_locales];
    }

    /**
     * @param  array{model: Model, locale: string|null, stale: list<array{row_locale: string|null, content_hash: string, text_index: int}>, processed_locales: list<string|null>}  $plan
     * @param  list<\NeuronAI\RAG\Document[]>  $embedded
     */
    private function writePlan(array $plan, string $model_key, array $embedded): void
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
                /** @phpstan-ignore method.notFound */
                $model->embeddings()->get()
                    ->reject(static fn (ModelEmbedding $row): bool => in_array($row->locale, $plan['processed_locales'], true))
                    ->each(static fn (ModelEmbedding $row) => $row->delete());
            }

            event(new ModelPreProcessingCompleted($model, 'embeddings'));
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
