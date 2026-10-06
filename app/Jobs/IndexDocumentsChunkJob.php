<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchIndexes;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
use Throwable;

/**
 * Writes the search documents of one chunk of an embedding model switch (one embeddable model, one
 * key range) with the target's vectors, then records the chunk as written in the switch state. The
 * `indexes` phase dispatches it once the indexes are recreated or emptied, the `verify` phase to
 * refresh every document before its checks; `$phase` says which plan the chunk belongs to.
 *
 * It is idempotent: writing a chunk again writes the same documents again. A chunk the state no
 * longer expects (written already, replaced by a new plan, a switch failed, abandoned or ended) is
 * skipped without writing. The completion is recorded through {@see EmbeddingSwitchStore::update()},
 * so chunk jobs running side by side, or next to the switch job, lose no count.
 *
 * It runs on its own queue, {@see self::QUEUE}, watched by the Horizon supervisor
 * `supervisor-embeddings-index` with 1 process, so pgvector index writes are not parallel, and a
 * timeout above {@see self::TIMEOUT_SECONDS}. A chunk that fails its tries stays pending: the switch
 * job dispatches it again in its next round and fails the phase naming it after the last one.
 */
final class IndexDocumentsChunkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const string QUEUE = 'embeddings-index';

    /**
     * The longest one chunk may take: {@see EmbeddingSwitchIndexes::DEFAULT_CHUNK_SIZE} records read
     * with their target rows and written to the search engine in bulk.
     */
    public const int TIMEOUT_SECONDS = 240;

    public int $tries = 3;

    public int $timeout = self::TIMEOUT_SECONDS;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30];

    /**
     * @param  array{model: string, from: int|string|null, to: int|string|null}  $chunk
     */
    public function __construct(
        public readonly string $phase,
        public readonly string $target,
        public readonly string $chunkId,
        public readonly array $chunk,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(EmbeddingSwitchStore $store, EmbeddingSwitchIndexes $indexes, EmbeddingModelRegistry $registry): void
    {
        if (! $store->get()->expectsChunk($this->phase, $this->target, $this->chunkId, $this->chunk)) {
            return;
        }

        $indexes->writeChunk($registry->get($this->target), $this->chunk);

        $store->update(fn (EmbeddingSwitchState $state): EmbeddingSwitchState => $state->withChunkCompleted(
            $this->phase,
            $this->target,
            $this->chunkId,
            $this->chunk,
            now()->toIso8601String(),
        ));
    }

    /**
     * The chunk stays pending in the state: the switch job dispatches it again in its next round.
     */
    public function failed(Throwable $exception): void
    {
        Log::warning('IndexDocumentsChunkJob failed', [
            'phase' => $this->phase,
            'target' => $this->target,
            'chunk' => $this->chunkId,
            'error' => $exception->getMessage(),
        ]);
    }
}
