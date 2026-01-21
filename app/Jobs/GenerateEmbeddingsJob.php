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
use Modules\AI\Services\EmbeddingService;
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
     * @var array|int[]
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

    public function middleware(): array
    {
        return [
            new ThrottlesExceptions(10, 5), // Max 10 exceptions in 5 minutes
            new RateLimited('embeddings'), // Rate limit for the embedding queue
        ];
    }

    /**
     * @throws ClientExceptionInterface
     * @throws JsonException
     */
    public function handle(EmbeddingService $embedding_service): void
    {
        $data = $this->model->prepareDataToEmbed();

        if ($data === null || $data === '') {
            return;
        }

        try {
            $embeddedDocuments = $embedding_service->embedDocument($data);

            foreach ($embeddedDocuments as $embeddedDocument) {
                $this->model->embeddings()->create(['embedding' => $embeddedDocument->embedding]);
            }

            // Emit event: pre-processing completed
            event(new ModelPreProcessingCompleted($this->model, 'embeddings'));
        } catch (Exception $exception) {
            Log::error('Embedding generation failed for model: ' . $this->model::class, [
                'model_id' => $this->model->id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            throw $exception; // Rethrow to make the join chain fails
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('GenerateEmbeddingsJob failed', [
            'model' => $this->model::class,
            'model_id' => $this->model->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
