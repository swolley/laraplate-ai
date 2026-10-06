<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Retrieval;

use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use RuntimeException;

/**
 * The query side of a documentation retrieval: the question with the query prefix of the active
 * embedding model, embedded. Used by the retrievals that own an `embedding_service` property.
 */
trait EmbedsDocumentationQuery
{
    /**
     * @throws RuntimeException when the provider returns no vector
     *
     * @return list<float>
     */
    private function embedQuestion(string $question): array
    {
        $prefix = app(EmbeddingModelRegistry::class)->active()->queryPrefix;
        $embedding = $this->embedding_service->embedText($prefix . $question);

        if ($embedding === []) {
            throw new RuntimeException;
        }

        return array_values($embedding);
    }
}
