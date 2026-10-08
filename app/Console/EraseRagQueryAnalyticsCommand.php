<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Modules\AI\Services\Documentation\Analytics\RagQueryRetention;
use Override;
use Throwable;

/**
 * The erasure entry point of the documentation query log. No user-erasure flow exists yet to call
 * it; when one does, it calls {@see RagQueryRetention::eraseUser()}.
 */
final class EraseRagQueryAnalyticsCommand extends Command
{
    #[Override]
    protected $signature = 'ai:erase-rag-queries {user : The id of the user whose logged questions are deleted}';

    #[Override]
    protected $description = 'Delete every logged documentation question of a user <fg=magenta>(✨ Modules\\AI)</fg=magenta>';

    public function handle(RagQueryRetention $retention): int
    {
        $user_id = (string) $this->argument('user');

        try {
            $deleted = $retention->eraseUser($user_id);
        } catch (Throwable $throwable) {
            $this->error('Failed to erase the logged documentation questions: ' . $throwable->getMessage());

            return self::FAILURE;
        }

        $this->info("Deleted {$deleted} logged documentation questions of user {$user_id}.");

        return self::SUCCESS;
    }
}
