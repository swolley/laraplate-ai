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

        foreach ($models as $model) {
            $fresh = $model->fresh() ?? $model;

            if (! $this->isEmbeddable($fresh)) {
                continue;
            }

            $this->syncModel($fresh, $locale, $model_key, $default_locale);
        }
    }

    private function syncModel(Model $model, ?string $locale, string $model_key, string $default_locale): void
    {
        /** @phpstan-ignore method.notFound */
        $by_locale = $model->prepareDataToEmbedByLocale($locale);

        if ($by_locale === []) {
            return;
        }

        try {
            $is_translated = class_uses_trait($model, HasTranslations::class);

            $existing = ($locale === null
                /** @phpstan-ignore method.notFound */
                ? $model->embeddings()
                /** @phpstan-ignore method.notFound */
                : $model->embeddings()->forLocale($locale)
            )->get();

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

                /** @phpstan-ignore method.notFound */
                $model->embeddings()->forLocale($row_locale)->delete();

                foreach ($this->embeddingService->embedDocument($text) as $document) {
                    /** @phpstan-ignore method.notFound */
                    $model->embeddings()->create([
                        'embedding' => $document->embedding,
                        'locale' => $row_locale,
                        'model_key' => $model_key,
                        'content_hash' => $content_hash,
                    ]);
                }
            }

            if ($locale === null) {
                /** @phpstan-ignore method.notFound */
                $model->embeddings()->get()
                    ->reject(static fn (ModelEmbedding $row): bool => in_array($row->locale, $processed_locales, true))
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
