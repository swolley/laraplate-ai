<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchOrchestrator;
use Throwable;

/**
 * Drives an embedding model switch: advances it one phase and dispatches itself again, with a
 * delay, while the switch runs. It carries no data: the persisted switch state says where the switch
 * stands, so a lost or restarted worker loses nothing.
 *
 * Runs never overlap: a run that finds another one advancing (a retry handed out while the first is
 * still working, a re-dispatch next to it) is dropped, and the run in progress carries the switch
 * on. On the sync connection the follow-up cannot be queued, so the run advances in place until the
 * switch stops running.
 */
final class SwitchEmbeddingModelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int REDISPATCH_DELAY_SECONDS = 5;

    /**
     * The longest a phase may take: the `indexes` phase rewrites every index in one run.
     */
    public const int TIMEOUT_SECONDS = 900;

    private const string OVERLAP_KEY = 'ai:embeddings:switch:run';

    /**
     * The overlap lock outlives the timeout, so a run killed by it frees the lock soon after.
     */
    private const int OVERLAP_LOCK_SECONDS = self::TIMEOUT_SECONDS + 60;

    private const int MAX_SYNC_STEPS = 50;

    public int $tries = 3;

    public int $timeout = self::TIMEOUT_SECONDS;

    /**
     * A run killed by the timeout fails at once: its retry would find the overlap lock still held
     * and be dropped, and the switch would stay `running` with nothing recorded.
     */
    public bool $failOnTimeout = true;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30];

    public static function overlapMiddleware(): WithoutOverlapping
    {
        return new WithoutOverlapping(self::OVERLAP_KEY)
            ->shared()
            ->dontRelease()
            ->expireAfter(self::OVERLAP_LOCK_SECONDS);
    }

    public static function overlapLockKey(): string
    {
        return self::overlapMiddleware()->getLockKey(new self());
    }

    /**
     * Frees the overlap lock of a run that died holding it. Only for a switch no run is advancing:
     * a failed one, or a running one that stopped making progress.
     */
    public static function releaseOverlapLock(): void
    {
        Cache::lock(self::overlapLockKey())->forceRelease();
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [self::overlapMiddleware()];
    }

    public function handle(EmbeddingSwitchOrchestrator $orchestrator): void
    {
        $state = $orchestrator->advance();

        if ($this->job instanceof SyncJob) {
            for ($steps = self::MAX_SYNC_STEPS; $steps > 0 && $orchestrator->canAdvance($state); $steps--) {
                $state = $orchestrator->advance();
            }

            return;
        }

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

        $error = $exception instanceof TimeoutExceededException
            ? 'the switch job timed out after ' . self::TIMEOUT_SECONDS . " s; run ai:embeddings:switch --resume ({$exception->getMessage()})"
            : $exception->getMessage();

        app(EmbeddingSwitchOrchestrator::class)->fail($error);
    }
}
