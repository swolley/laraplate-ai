<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchOrchestrator;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
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
 *
 * Every run goes to its own queue, {@see self::QUEUE}, watched by the Horizon supervisor
 * `supervisor-embeddings-switch` with a timeout above {@see self::TIMEOUT_SECONDS}. Not the
 * `embeddings` queue nor `embeddings-index`: the embeddings phase waits while the first holds jobs
 * and the chunked phases while the second does, so a run queued there would wait for itself.
 *
 * A run does not write the search documents: the `indexes` and `verify` phases hand them to
 * `IndexDocumentsChunkJob` chunks and only wait for them.
 */
final class SwitchEmbeddingModelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string QUEUE = 'embeddings-switch';

    public const int REDISPATCH_DELAY_SECONDS = 5;

    /**
     * The longest one run may take. The longest run left is the step that prepares the `indexes`
     * phase: it recreates or empties each index and, when FAQ keeps its documents in Elasticsearch,
     * rebuilds the documentation indexes in one go (`ai:index-rag-docs --full` embeds every
     * documentation document with the target), which depends on the documentation size and on the
     * provider and is not bounded by the corpus chunking. Every other run walks the corpus or
     * deletes the previous model's rows, seconds to a minute.
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

    public function __construct()
    {
        $this->onQueue(self::QUEUE);
    }

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
     * Records the failure in the switch state, so the panel and `ai:embeddings:status` show it. This
     * is the last chance to record it: when the state lock cannot be had (a writer holding it, a lock
     * timeout being what failed the job), the failed state is written without the lock. A chunk
     * completion racing that write can be lost; the chunk then stays pending and is written again
     * on `--resume`.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('SwitchEmbeddingModelJob failed', ['error' => $exception->getMessage()]);

        $error = $exception instanceof TimeoutExceededException
            ? 'the switch job timed out after ' . self::TIMEOUT_SECONDS . " s; run ai:embeddings:switch --resume ({$exception->getMessage()})"
            : $exception->getMessage();

        try {
            app(EmbeddingSwitchOrchestrator::class)->fail($error);
        } catch (LockTimeoutException) {
            $store = app(EmbeddingSwitchStore::class);
            $state = $store->get();

            if ($state->status === 'running') {
                $store->put($state->with(status: 'failed', error: $error, updatedAt: now()->toIso8601String()));
            }
        }
    }
}
