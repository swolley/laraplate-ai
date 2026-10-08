<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Illuminate\Console\Command;
use Modules\AI\Services\Documentation\Analytics\RagQueryLogIndex;
use Override;
use Throwable;

final class CreateRagQueryAnalyticsIndexCommand extends Command
{
    #[Override]
    protected $signature = 'ai:create-rag-query-index
                            {--force : Delete the index first, losing the logged questions, so it takes the current mapping}';

    #[Override]
    protected $description = 'Create the index of the documentation query log <fg=magenta>(✨ Modules\\AI)</fg=magenta>';

    public function handle(Client $client): int
    {
        $index = RagQueryLogIndex::name();

        try {
            $response = $client->indices()->exists(['index' => $index]);
            $exists = $response instanceof Elasticsearch && $response->asBool();

            if ($exists && ! $this->option('force')) {
                $this->info("The documentation query log index [{$index}] already exists. Pass --force to recreate it.");

                return self::SUCCESS;
            }

            if ($exists) {
                $client->indices()->delete(['index' => $index]);
            }

            $client->indices()->create([
                'index' => $index,
                'body' => ['mappings' => RagQueryLogIndex::mappings()],
            ]);

            $this->info("The documentation query log index [{$index}] is ready.");

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error('Failed to create the documentation query log index: ' . $throwable->getMessage());

            return self::FAILURE;
        }
    }
}
