<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

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
use Modules\AI\Contracts\IEmbeddingService;
use Modules\Core\Contracts\IEmbeddableModel;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Search\Traits\Searchable;
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
     * Maximum time to wait in the queue before execution.
     */
    public int $maxExceptionsThenWait = 300;

    public function __construct(
        private readonly Model $model,
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

        $data = $model->prepareDataToEmbed();

        if ($data === null || $data === '') {
            return;
        }

        try {
            $embedded_documents = $embedding_service->embedDocument($data);

            foreach ($embedded_documents as $embedded_document) {
                $model->embeddings()->create([
                    'embedding' => $embedded_document->embedding,
                ]);
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
