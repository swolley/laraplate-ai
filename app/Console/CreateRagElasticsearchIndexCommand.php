<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\Core\Services\ElasticsearchService;
use Override;
use Throwable;

use function ai_config_bool;
use function ai_config_int;
use function ai_config_string;

final class CreateRagElasticsearchIndexCommand extends Command
{
    #[Override]
    protected $signature = 'ai:create-rag-es-index';

    #[Override]
    protected $description = 'Create or update the Elasticsearch index for documentation RAG <fg=magenta>(✨ Modules\\AI)</fg=magenta>';

    public function handle(): int
    {
        if (! ai_config_bool('ai.features.faq.enabled', true)) {
            $this->warn('FAQ/RAG is disabled in config (ai.features.faq.enabled).');

            return self::FAILURE;
        }

        $index = ai_config_string('ai.features.faq.elasticsearch.index', 'laraplate_rag_docs');
        $embedding_dims = ai_config_int('ai.features.faq.elasticsearch.embedding_dims', 384);

        try {
            ElasticsearchService::getInstance()->createIndex(
                $index,
                [],
                ElasticsearchRagVectorStore::indexMappings($embedding_dims),
            );

            $this->info("RAG Elasticsearch index [{$index}] is ready (embedding dims: {$embedding_dims}).");

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error('Failed to create RAG index: ' . $throwable->getMessage());

            return self::FAILURE;
        }
    }
}
