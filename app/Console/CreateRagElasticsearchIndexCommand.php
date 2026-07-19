<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use function ai_config_bool;
use function ai_config_int;
use Illuminate\Console\Command;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\Core\Services\ElasticsearchService;
use Override;
use Throwable;

final class CreateRagElasticsearchIndexCommand extends Command
{
    #[Override]
    protected $signature = 'ai:create-rag-index
                            {--profile=all : Index profile: developer, user, or all}';

    #[Override]
    protected $description = 'Create or update the RAG index for documentation <fg=magenta>(✨ Modules\\AI)</fg=magenta>';

    public function handle(): int
    {
        if (! ai_config_bool('ai.features.faq.enabled', true)) {
            $this->warn('FAQ/RAG is disabled in config (ai.features.faq.enabled).');

            return self::FAILURE;
        }

        $embedding_dims = ai_config_int('ai.features.faq.elasticsearch.embedding_dims', 384);
        $profile_option = $this->option('profile');
        $profile_name = is_string($profile_option) ? mb_strtolower(trim($profile_option)) : '';

        if (! in_array($profile_name, ['developer', 'user', 'all'], true)) {
            $this->error('Invalid profile. Expected developer, user, or all.');

            return self::FAILURE;
        }

        try {
            $profiles = $profile_name === 'all'
                ? DocumentationIndexProfile::cases()
                : [DocumentationIndexProfile::from($profile_name)];

            foreach ($profiles as $profile) {
                $index = $profile->indexName();
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
