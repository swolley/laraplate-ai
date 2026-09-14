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
     *
     * @codeCoverageIgnore
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

            // Replace any previous embeddings so a retry or manual regeneration
            // does not append duplicate ModelEmbedding rows: all locales when
            // no locale was requested, otherwise only the requested locale's.
            if ($this->locale === null) {
                $model->embeddings()->delete();
            } else {
                $model->embeddings()->forLocale($this->locale)->delete();
            }

            foreach ($byLocale as $loc => $text) {
                $documents = $embedding_service->embedDocument($text);

                foreach ($documents as $document) {
                    $model->embeddings()->create([
                        'embedding' => $document->embedding,
                        'locale' => $loc === (config('app.locale') ?: 'en') && ! class_uses_trait($model, HasTranslations::class) ? null : $loc,
                        'model_key' => $modelKey,
                    ]);
                }
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
