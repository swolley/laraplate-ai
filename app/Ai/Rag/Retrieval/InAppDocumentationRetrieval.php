<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Retrieval;

use function ai_config_string;

use Closure;
use InvalidArgumentException;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Documentation\DocumentAudiencePolicy;
use NeuronAI\RAG\Document;
use RuntimeException;
use Throwable;

final readonly class InAppDocumentationRetrieval
{
    /**
     * @param (Closure(array<float>, DocumentationRetrievalContext): array<Document>)|null $search
     */
    public function __construct(
        private IEmbeddingService $embedding_service,
        private ?Closure $search = null,
    ) {}

    /**
     * @return list<Document>
     */
    public function retrieve(string $question, AssistantAccessContext $access): array
    {
        $question = trim($question);

        if ($question === '') {
            throw new InvalidArgumentException('In-app documentation question cannot be blank.');
        }

        $context = DocumentationRetrievalContext::fromAccessContext($access);

        try {
            $embedding = $this->embedding_service->embedText($question);

            if ($embedding === []) {
                throw new RuntimeException;
            }

            $documents = $this->search !== null
                ? ($this->search)($embedding, $context)
                : $this->searchUserIndex($embedding, $context);

            return $this->safeDocuments($documents);
        } catch (Throwable) {
            throw new RuntimeException('In-app documentation retrieval is unavailable.');
        }
    }

    /**
     * @param list<float> $embedding
     * @return list<Document>
     */
    private function searchUserIndex(array $embedding, DocumentationRetrievalContext $context): array
    {
        $store = ElasticsearchRagVectorStore::fromConfig(
            DocumentationIndexProfile::User,
            $context->topK,
        );

        if (! $store->hasDocuments()) {
            throw new RuntimeException;
        }

        return $store->similaritySearchForContext($embedding, $context);
    }

    /**
     * @param array<Document> $documents
     * @return list<Document>
     */
    private function safeDocuments(array $documents): array
    {
        $classification_version = ai_config_string(
            'ai.features.faq.policy_classification_version',
            'in-app-docs-v1',
        );
        $policy = new DocumentAudiencePolicy($classification_version);
        $safe_documents = [];

        foreach ($documents as $document) {
            if (! $document instanceof Document
                || ! $policy->allows($document, DocumentationIndexProfile::User)) {
                throw new RuntimeException;
            }

            $metadata = $document->metadata;

            if (($metadata['permissions_metadata_validated'] ?? null) !== true
                || ($metadata['required_permissions_count'] ?? null) !== count($metadata['required_permissions'])) {
                throw new RuntimeException;
            }

            $safe_document = new Document($document->getContent());
            $safe_document->sourceType = 'documentation';
            $safe_document->sourceName = $metadata['safe_source_label'];
            $safe_document->metadata = array_intersect_key($metadata, array_flip([
                'audience',
                'heading_breadcrumb',
                'locale',
                'module',
                'safe_source_label',
                'version',
            ]));
            $safe_document->setScore($document->getScore());
            $safe_documents[] = $safe_document;
        }

        return $safe_documents;
    }
}
