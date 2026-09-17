<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Retrieval;

use function ai_config_int;

use Closure;
use InvalidArgumentException;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Contracts\IEmbeddingService;
use NeuronAI\RAG\Document;
use RuntimeException;
use Throwable;

/**
 * Vector retrieval over the developer documentation index.
 *
 * Unlike {@see InAppDocumentationRetrieval}, this path serves the trusted
 * developer corpus: no per-user ACL filter, no audience gate, no safe
 * projection. It exists so the developer index can be measured by
 * `ai:evaluate-documentation` without weakening the user-facing in-app path.
 */
final readonly class DeveloperDocumentationRetrieval
{
    /**
     * @param  (Closure(array<float>): array<Document>)|null  $search
     */
    public function __construct(
        private IEmbeddingService $embedding_service,
        private ?Closure $search = null,
    ) {}

    /**
     * @return list<Document>
     */
    public function retrieve(string $question): array
    {
        $question = mb_trim($question);

        if ($question === '') {
            throw new InvalidArgumentException('Developer documentation question cannot be blank.');
        }

        try {
            $query_prefix = app(EmbeddingModelRegistry::class)->active()->queryPrefix;
            $embedding = $this->embedding_service->embedText($query_prefix . $question);

            if ($embedding === []) {
                throw new RuntimeException;
            }

            $documents = $this->search !== null
                ? ($this->search)($embedding)
                : $this->searchDeveloperIndex($embedding);

            return $this->onlyValidDocuments($documents);
        } catch (Throwable) {
            throw new RuntimeException('Developer documentation retrieval is unavailable.');
        }
    }

    /**
     * @param  list<float>  $embedding
     * @return list<Document>
     */
    private function searchDeveloperIndex(array $embedding): array
    {
        $top_k = min(max(ai_config_int('ai.features.faq.max_documents', 5), 1), 10);
        $store = ElasticsearchRagVectorStore::fromConfig(DocumentationIndexProfile::Developer, $top_k);

        if (! $store->hasDocuments()) {
            throw new RuntimeException;
        }

        return $store->similaritySearch($embedding);
    }

    /**
     * @param  array<Document>  $documents
     * @return list<Document>
     */
    private function onlyValidDocuments(array $documents): array
    {
        $valid = [];

        foreach ($documents as $document) {
            if (! $document instanceof Document || ! is_string($document->sourceName)) {
                throw new RuntimeException;
            }

            $valid[] = $document;
        }

        return $valid;
    }
}
