<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
use Override;

/**
 * Prints where an embedding model switch stands, the same information the settings page shows.
 */
final class EmbeddingsStatusCommand extends Command
{
    #[Override]
    protected $signature = 'ai:embeddings:status';

    #[Override]
    protected $description = 'Show the state of the embedding model switch and its counts <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function handle(EmbeddingSwitchStore $store, EmbeddingModelRegistry $registry): int
    {
        $state = $store->get();

        $this->table(['', ''], [
            ['active model', $registry->activeKey()],
            ['status', $state->status],
            ['phase', $state->phase ?? '-'],
            ['target', $state->target ?? '-'],
            ['previous', $state->previous ?? '-'],
            ['progress', "{$state->done}/{$state->total}"],
            ['started at', $state->startedAt ?? '-'],
            ['error', $state->error ?? '-'],
        ]);

        return self::SUCCESS;
    }
}
