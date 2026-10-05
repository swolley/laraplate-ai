<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag;

use function ai_config_bool;
use function ai_config_string;

use Illuminate\Support\Facades\Artisan;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Contracts\IRagIndexRebuilder;
use Override;
use RuntimeException;

/**
 * Rebuilds the Elasticsearch documentation indexes for a target embedding profile during a model
 * switch: `ai:create-rag-index --force` recreates them sized for the target's vectors and
 * `ai:index-rag-docs --full` embeds every document again, both with the target as the active
 * profile. The indexes are always recreated: the full reindex replaces every document anyway.
 * Nothing happens when FAQ/RAG is off or its documents are not kept in Elasticsearch.
 */
final readonly class RagIndexRebuilder implements IRagIndexRebuilder
{
    public function __construct(private EmbeddingModelRegistry $registry) {}

    #[Override]
    public function rebuild(EmbeddingModelProfile $target): void
    {
        if (! ai_config_bool('ai.features.faq.enabled', true) || ai_config_string('ai.features.faq.vector_store', 'filesystem') !== 'elasticsearch') {
            return;
        }

        $this->registry->withActive($target->key, function (): void {
            $this->call('ai:create-rag-index', ['--profile' => 'all', '--force' => true]);
            $this->call('ai:index-rag-docs', ['--profile' => 'all', '--full' => true]);
        });
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function call(string $command, array $parameters): void
    {
        if (Artisan::call($command, $parameters) !== 0) {
            throw new RuntimeException("{$command} failed: " . mb_trim(Artisan::output()));
        }
    }
}
