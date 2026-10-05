<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use JsonException;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Services\ModelEmbeddingSynchronizer;
use Modules\Core\Events\ModelPreProcessingCompleted;
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
     * Seconds the worker waits after each unhandled exception. Together with $maxExceptions it
     * sets how long an embedding outage is tolerated before the job fails: four waits, 7.5 minutes,
     * which stays inside the ten minutes the indexing coordination event lives in cache.
     *
     * @var list<int>
     */
    public array $backoff = [30, 60, 120, 240];

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
    public int $maxExceptions = 5;

    private bool $throttled = true;

    public function __construct(
        private readonly Model $model,
        private readonly ?string $locale = null,
        private readonly ?string $profile = null,
    ) {
        $this->onQueue('embeddings');
    }

    /**
     * Drops the rate limiter, for a run the operator paces by hand (a sync repair). It works by
     * releasing the job back to the queue, which a sync run does not have: the job would be
     * lost without an error, skipped once the limit is reached.
     */
    public function unthrottled(): self
    {
        $this->throttled = false;

        return $this;
    }

    /**
     * Only the rate limiter, and no middleware that catches exceptions: an embedding error has to
     * reach the worker, which counts it against $maxExceptions and fails the job once the budget is
     * spent, so failed() can degrade the document to keyword-only. A catch-and-release middleware
     * (ThrottlesExceptions) never let an error count, and the job waited out its whole retryUntil
     * without failing or leaving a trace.
     *
     * @return array<int, RateLimited>
     */
    public function middleware(): array
    {
        if (! $this->throttled) {
            return [];
        }

        return [new RateLimited('embeddings')];
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
        $registry = app(EmbeddingModelRegistry::class);
        $synchronizer = new ModelEmbeddingSynchronizer($embedding_service, $registry);

        if ($this->profile === null) {
            $synchronizer->sync([$this->model], $this->locale);

            return;
        }

        $registry->withActive($this->profile, fn () => $synchronizer->sync([$this->model], $this->locale));
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
}
