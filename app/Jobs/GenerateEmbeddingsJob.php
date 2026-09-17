<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use DateTimeInterface;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use JsonException;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\Core\Contracts\IEmbeddableModel;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Models\ModelEmbedding;
use Psr\Http\Client\ClientExceptionInterface;
use Throwable;

final class GenerateEmbeddingsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60, 120];

    /**
     * Job timeout in seconds
     * 180s (3 min) considering:
     * - 30s per OpenAI call
     * - Multiple calls for long documents
     * - Buffer for network latency and retries.
     */
    public int $timeout = 300;

    /**
     * Unhandled exceptions allowed before the job fails. Rate-limit releases are
     * not exceptions, so they do not consume this budget.
     */
    public int $maxExceptions = 3;

    public function __construct(
        private readonly Model $model,
        private readonly ?string $locale = null,
    ) {
        $this->onQueue('embeddings');
    }

    /**
     * @return array<int, ThrottlesExceptions|RateLimited>
     */
    public function middleware(): array
    {
        return [
            new ThrottlesExceptions(10, 5),
            new RateLimited('embeddings'),
        ];
    }

    /**
     * Time-based retry bound. It takes precedence over the tries count (including
     * Horizon's supervisor `tries`), so a job repeatedly released by the
     * `embeddings` rate limiter during a mass backfill waits for its slot instead
     * of dying with MaxAttemptsExceeded. Real errors are still bounded by
     * $maxExceptions.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes((int) config('ai.features.embeddings.retry_until_minutes', 1440));
    }

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    public function handle(IEmbeddingService $embedding_service): void
    {
        $model = $this->model->fresh() ?? $this->model;

        if (! $model instanceof Model || ! $this->isEmbeddable($model)) {
            return;
        }

        $byLocale = $model->prepareDataToEmbedByLocale($this->locale);

        if ($byLocale === []) {
            return;
        }

        try {
            $modelKey = app(EmbeddingModelRegistry::class)->active()->key;
            $isTranslated = class_uses_trait($model, HasTranslations::class);
            $defaultLocale = config('app.locale') ?: 'en';

            // Snapshot of the existing embeddings in scope, so a locale whose text
            // and embedding model are both unchanged can be kept instead of being
            // deleted and recomputed. This avoids re-embedding every locale on each
            // reindex; only changed or model-stale locales hit the embedding service.
            $existing = ($this->locale === null
                ? $model->embeddings()
                : $model->embeddings()->forLocale($this->locale)
            )->get();

            $processedLocales = [];

            foreach ($byLocale as $loc => $text) {
                $rowLocale = ($loc === $defaultLocale && ! $isTranslated) ? null : $loc;
                $processedLocales[] = $rowLocale;
                $contentHash = hash('sha256', $text);

                $isFresh = $existing->first(static fn (ModelEmbedding $row): bool => $row->locale === $rowLocale
                    && $row->model_key === $modelKey
                    && $row->content_hash === $contentHash) !== null;

                if ($isFresh) {
                    continue;
                }

                // Replace only this locale's rows so a retry or partial change does
                // not append duplicates or touch fresh locales.
                $model->embeddings()->forLocale($rowLocale)->delete();

                foreach ($embedding_service->embedDocument($text) as $document) {
                    $model->embeddings()->create([
                        'embedding' => $document->embedding,
                        'locale' => $rowLocale,
                        'model_key' => $modelKey,
                        'content_hash' => $contentHash,
                    ]);
                }
            }

            // On a full run, drop rows for locales that no longer exist (e.g. a
            // removed translation) so the search document keeps no stale vector.
            if ($this->locale === null) {
                $model->embeddings()->get()
                    ->reject(static fn (ModelEmbedding $row): bool => in_array($row->locale, $processedLocales, true))
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

    /**
     * @codeCoverageIgnore
     */
    public function failed(Throwable $exception): void
    {
        Log::error('GenerateEmbeddingsJob failed', [
            'model' => $this->model::class,
            'model_id' => $this->model->getKey(),
            'error' => $exception->getMessage(),
        ]);

        // Degrade gracefully: signal that this pre-processing step is done so the
        // finalize listener still indexes the document (keyword-only, without the
        // vector). Otherwise a failed embedding would keep the document out of the
        // search index entirely.
        event(new ModelPreProcessingCompleted($this->model, 'embeddings'));
    }

    /**
     * @phpstan-assert-if-true IEmbeddableModel&Model $model
     */
    private function isEmbeddable(Model $model): bool
    {
        return is_callable([$model, 'prepareDataToEmbed'])
            && is_callable([$model, 'embeddings']);
    }
}
