<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Core\Services\ElasticsearchService;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use stdClass;
use Throwable;

use function ai_config_int;
use function ai_config_string;
use function count;
use function is_array;
use function is_numeric;
use function is_string;
use function min;

/**
 * Elasticsearch-backed vector store for documentation RAG.
 *
 * Retrieval fails fast on transport errors so the agent does not answer without retrieved context.
 */
final class ElasticsearchRagVectorStore implements VectorStoreInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly string $index,
        private readonly int $topK = 5,
        private readonly int $embedding_dims = 384,
    ) {
        if ($this->index === '') {
            throw new InvalidArgumentException('Elasticsearch RAG index name cannot be empty.');
        }

        if ($this->embedding_dims <= 0) {
            throw new InvalidArgumentException('Elasticsearch RAG embedding dimensions must be positive.');
        }
    }

    public static function fromConfig(int $topK): self
    {
        return new self(
            client: ElasticsearchService::getInstance()->client,
            index: ai_config_string('ai.features.faq.elasticsearch.index', 'laraplate_rag_docs'),
            topK: $topK,
            embedding_dims: ai_config_int('ai.features.faq.elasticsearch.embedding_dims', 384),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function indexMappings(int $embedding_dims): array
    {
        return [
            'properties' => [
                'content' => ['type' => 'text'],
                'sourceType' => ['type' => 'keyword'],
                'sourceName' => ['type' => 'keyword'],
                'metadata' => ['type' => 'object', 'enabled' => true],
                'neuron_id' => ['type' => 'keyword'],
                'indexed_at' => ['type' => 'date'],
                'embedding' => [
                    'type' => 'dense_vector',
                    'dims' => $embedding_dims,
                    'index' => true,
                    'similarity' => 'cosine',
                ],
            ],
        ];
    }

    public function indexExists(): bool
    {
        try {
            return $this->client->indices()->exists(['index' => $this->index])->asBool();
        } catch (Throwable $throwable) {
            Log::warning('Elasticsearch RAG index exists check failed', [
                'index' => $this->index,
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    public function hasDocuments(): bool
    {
        if (! $this->indexExists()) {
            return false;
        }

        try {
            $response = $this->client->count(['index' => $this->index])->asArray();
            $count = $response['count'] ?? 0;

            return is_numeric($count) && (int) $count > 0;
        } catch (Throwable $throwable) {
            Log::warning('Elasticsearch RAG document count failed', [
                'index' => $this->index,
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }
    }

    public function clearIndex(): void
    {
        if (! $this->indexExists()) {
            return;
        }

        try {
            $this->client->deleteByQuery([
                'index' => $this->index,
                'body' => [
                    'query' => ['match_all' => new stdClass],
                ],
            ]);
        } catch (ClientResponseException|ServerResponseException $throwable) {
            throw new VectorStoreException(
                'Failed to clear Elasticsearch RAG index: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * @param  Document[]  $documents
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        if ($documents === []) {
            return $this;
        }

        $body = [];

        foreach ($documents as $document) {
            $id = (string) $document->id;
            $body[] = ['index' => ['_index' => $this->index, '_id' => $id]];
            $body[] = $this->documentToSource($document);
        }

        try {
            $response = $this->client->bulk(['body' => $body])->asArray();

            if (($response['errors'] ?? false) === true) {
                throw new VectorStoreException('Elasticsearch bulk indexing reported errors.');
            }
        } catch (VectorStoreException $throwable) {
            throw $throwable;
        } catch (Throwable $throwable) {
            throw new VectorStoreException(
                'Failed to index RAG documents in Elasticsearch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }

        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        $must = [['term' => ['sourceType' => $sourceType]]];

        if ($sourceName !== null) {
            $must[] = ['term' => ['sourceName' => $sourceName]];
        }

        try {
            $this->client->deleteByQuery([
                'index' => $this->index,
                'body' => [
                    'query' => [
                        'bool' => [
                            'must' => $must,
                        ],
                    ],
                ],
            ]);
        } catch (Throwable $throwable) {
            throw new VectorStoreException(
                'Failed to delete RAG documents from Elasticsearch: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }

        return $this;
    }

    /**
     * @param  float[]  $embedding
     * @return Document[]
     */
    public function similaritySearch(array $embedding): array
    {
        $num_candidates = min($this->topK * 10, 100);

        try {
            $response = $this->client->search([
                'index' => $this->index,
                'body' => [
                    'size' => $this->topK,
                    'knn' => [
                        'field' => 'embedding',
                        'query_vector' => $embedding,
                        'k' => $this->topK,
                        'num_candidates' => $num_candidates,
                    ],
                ],
            ])->asArray();
        } catch (Throwable $throwable) {
            throw new VectorStoreException(
                'Elasticsearch RAG similarity search failed: ' . $throwable->getMessage(),
                (int) $throwable->getCode(),
                $throwable,
            );
        }

        return $this->mapHitsToDocuments($response);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return Document[]
     */
    private function mapHitsToDocuments(array $response): array
    {
        $hits = $response['hits']['hits'] ?? [];

        if (! is_array($hits)) {
            return [];
        }

        $documents = [];

        foreach ($hits as $hit) {
            if (! is_array($hit)) {
                continue;
            }

            $source = $hit['_source'] ?? [];

            if (! is_array($source)) {
                continue;
            }

            $content = $source['content'] ?? '';

            if (! is_string($content)) {
                continue;
            }

            $document = new Document($content);
            $document->sourceType = is_string($source['sourceType'] ?? null) ? $source['sourceType'] : 'manual';
            $document->sourceName = is_string($source['sourceName'] ?? null) ? $source['sourceName'] : 'manual';
            $document->embedding = is_array($source['embedding'] ?? null) ? $source['embedding'] : [];
            $document->metadata = is_array($source['metadata'] ?? null) ? $source['metadata'] : [];
            $document->id = is_string($source['neuron_id'] ?? null)
                ? $source['neuron_id']
                : (is_string($hit['_id'] ?? null) ? $hit['_id'] : $document->id);
            $document->setScore((float) ($hit['_score'] ?? 0.0));

            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * @return array<string, mixed>
     */
    private function documentToSource(Document $document): array
    {
        return [
            'content' => $document->getContent(),
            'sourceType' => $document->sourceType,
            'sourceName' => $document->sourceName,
            'embedding' => $document->embedding,
            'metadata' => $document->metadata,
            'neuron_id' => (string) $document->id,
            'indexed_at' => now()->toIso8601String(),
        ];
    }
}
