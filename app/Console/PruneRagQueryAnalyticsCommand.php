<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Modules\AI\Services\Documentation\Analytics\RagQueryRetention;
use Override;
use Throwable;

final class PruneRagQueryAnalyticsCommand extends Command
{
    #[Override]
    protected $signature = 'ai:prune-rag-queries';

    #[Override]
    protected $description = 'Delete the logged documentation questions older than the retention window <fg=magenta>(✨ Modules\\AI)</fg=magenta>';

    public function handle(RagQueryRetention $retention): int
    {
        try {
            $deleted = $retention->prune();
        } catch (Throwable $throwable) {
            $this->error('Failed to prune the documentation query log: ' . $throwable->getMessage());

            return self::FAILURE;
        }

        $this->info("Deleted {$deleted} logged documentation questions past the retention window.");

        return self::SUCCESS;
    }
}
