<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchOrchestrator;
use Throwable;

/**
 * Drives an embedding model switch: advances it one phase and dispatches itself again, with a
 * delay, only while the next phase has a handler. It carries no data: the persisted switch state
 * says where the switch stands, so a lost or restarted worker loses nothing.
 */
final class SwitchEmbeddingModelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int REDISPATCH_DELAY_SECONDS = 5;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30];

    public function handle(EmbeddingSwitchOrchestrator $orchestrator): void
    {
        $state = $orchestrator->advance();

        if ($orchestrator->canAdvance($state)) {
            self::dispatch()->delay(now()->addSeconds(self::REDISPATCH_DELAY_SECONDS));
        }
    }

    /**
     * Records the failure in the switch state, so the panel and `ai:embeddings:status` show it.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('SwitchEmbeddingModelJob failed', ['error' => $exception->getMessage()]);

        app(EmbeddingSwitchOrchestrator::class)->fail($exception->getMessage());
    }
}
