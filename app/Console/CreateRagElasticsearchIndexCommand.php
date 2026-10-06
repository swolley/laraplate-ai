<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use function ai_config_bool;

use Illuminate\Console\Command;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Console\Concerns\ResolvesDocumentationProfiles;
use Modules\Core\Services\ElasticsearchService;
use Override;
use Throwable;

final class CreateRagElasticsearchIndexCommand extends Command
{
    use ResolvesDocumentationProfiles;

    #[Override]
    protected $signature = 'ai:create-rag-index
                            {--profile=all : Index profile: developer, user, or all}
                            {--force : Delete each index first, so its vector mapping takes the dimensions of the active embedding profile}';

    #[Override]
    protected $description = 'Create or update the RAG index for documentation <fg=magenta>(✨ Modules\\AI)</fg=magenta>';

    public function handle(): int
    {
        if (! ai_config_bool('ai.features.faq.enabled', true)) {
            $this->warn('FAQ/RAG is disabled in config (ai.features.faq.enabled).');

            return self::FAILURE;
        }

        $embedding_dims = ElasticsearchRagVectorStore::activeDimensions();
        $force = (bool) $this->option('force');
        $profiles = $this->documentationProfiles();

        if ($profiles === null) {
            return self::FAILURE;
        }

        try {
            foreach ($profiles as $profile) {
                $index = $profile->indexName();

                // An existing dense_vector field cannot change its dimensions: only a new index can.
                if ($force) {
                    ElasticsearchService::getInstance()->deleteIndex($index);
                }

                ElasticsearchService::getInstance()->createIndex(
                    $index,
                    [],
                    ElasticsearchRagVectorStore::indexMappings($embedding_dims),
                );

                $this->info("RAG Elasticsearch {$profile->value} index [{$index}] is ready (embedding dims: {$embedding_dims}).");
            }

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error('Failed to create RAG index: ' . $throwable->getMessage());

            return self::FAILURE;
        }
    }
}
