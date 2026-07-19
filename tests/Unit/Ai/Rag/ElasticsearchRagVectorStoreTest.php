<?php

declare(strict_types=1);

use Elastic\Elasticsearch\Client;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;

function make_rag_vector_store(
    Client $client,
    DocumentationIndexProfile $profile = DocumentationIndexProfile::Developer,
): ElasticsearchRagVectorStore
{
    config()->set('ai.features.faq.elasticsearch.developer_index', 'laraplate_rag_docs_test');
    config()->set('ai.features.faq.elasticsearch.user_index', 'laraplate_rag_user_docs_test');

    return new ElasticsearchRagVectorStore(
        client: $client,
        indexProfile: $profile,
        topK: 2,
        embedding_dims: 3,
    );
}

test('index mappings include dense vector with configured dimensions', function (): void {
    $mappings = ElasticsearchRagVectorStore::indexMappings(384);

    expect($mappings['properties']['embedding']['dims'])->toBe(384)
        ->and($mappings['properties']['embedding']['type'])->toBe('dense_vector')
        ->and($mappings['properties']['metadata']['properties'])->toHaveKeys([
            'audience',
            'module',
            'locale',
            'canonical_source',
            'safe_source_label',
            'required_permissions',
            'permissions_metadata_validated',
            'required_permissions_count',
            'tenant_scope',
            'tenant_id',
            'version',
            'heading_breadcrumb',
            'policy_classification_version',
        ]);
});

test('profile selects a distinct configured physical index', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('count')
        ->once()
        ->with(['index' => 'laraplate_rag_user_docs_test'])
        ->andReturn(make_elasticsearch_response(['count' => 1]));

    expect(make_rag_vector_store($client, DocumentationIndexProfile::User)->hasDocuments())->toBeTrue();
});

test('matching developer and user index names are rejected', function (): void {
    config()->set('ai.features.faq.elasticsearch.developer_index', 'same_index');
    config()->set('ai.features.faq.elasticsearch.user_index', 'same_index');

    expect(fn () => new ElasticsearchRagVectorStore(
        client: Mockery::mock(Client::class),
        indexProfile: DocumentationIndexProfile::Developer,
        topK: 2,
        embedding_dims: 3,
    ))->toThrow(InvalidArgumentException::class);
});

test('similarity search maps knn hits to neuron documents', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('search')
        ->once()
        ->with(Mockery::on(function (array $params): bool {
            return $params['index'] === 'laraplate_rag_docs_test'
                && $params['body']['knn']['k'] === 2
                && $params['body']['knn']['field'] === 'embedding';
        }))
        ->andReturn(make_elasticsearch_response([
            'hits' => [
                'hits' => [
                    [
                        '_id' => 'doc-1',
                        '_score' => 0.91,
                        '_source' => [
                            'content' => 'First chunk',
                            'sourceType' => 'file',
                            'sourceName' => 'faq-module-AI',
                            'embedding' => [0.1, 0.2, 0.3],
                            'metadata' => ['section' => 'intro'],
                            'neuron_id' => 'doc-1',
                        ],
                    ],
                    [
                        '_id' => 'doc-2',
                        '_score' => 0.82,
                        '_source' => [
                            'content' => 'Second chunk',
                            'sourceType' => 'file',
                            'sourceName' => 'faq-module-Core',
                            'embedding' => [0.4, 0.5, 0.6],
                            'metadata' => [],
                            'neuron_id' => 'doc-2',
                        ],
                    ],
                ],
            ],
        ]));

    $store = make_rag_vector_store($client);
    $documents = $store->similaritySearch([0.1, 0.2, 0.3]);

    expect($documents)->toHaveCount(2)
        ->and($documents[0]->getContent())->toBe('First chunk')
        ->and($documents[0]->sourceName)->toBe('faq-module-AI')
        ->and($documents[0]->getScore())->toBe(0.91)
        ->and($documents[1]->getContent())->toBe('Second chunk');
});

test('add documents sends bulk index operations', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('bulk')
        ->once()
        ->with(Mockery::on(function (array $params): bool {
            $body = $params['body'] ?? [];

            return count($body) === 2
                && ($body[0]['index']['_index'] ?? null) === 'laraplate_rag_docs_test'
                && ($body[1]['content'] ?? null) === 'Chunk body'
                && ($body[1]['sourceType'] ?? null) === 'file';
        }))
        ->andReturn(make_elasticsearch_response(['errors' => false]));

    $document = new Document('Chunk body');
    $document->sourceType = 'file';
    $document->sourceName = 'faq-module-AI';
    $document->embedding = [0.1, 0.2, 0.3];

    $store = make_rag_vector_store($client);
    $store->addDocuments([$document]);

    expect(true)->toBeTrue();
});

test('delete by source type and name issues delete by query', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('deleteByQuery')
        ->once()
        ->with(Mockery::on(function (array $params): bool {
            $must = $params['body']['query']['bool']['must'] ?? [];

            return $params['index'] === 'laraplate_rag_docs_test'
                && count($must) === 2
                && ($must[0]['term']['sourceType'] ?? null) === 'file'
                && ($must[1]['term']['sourceName'] ?? null) === 'faq-module-AI';
        }))
        ->andReturn(make_elasticsearch_response(['deleted' => 1]));

    $store = make_rag_vector_store($client);
    $store->deleteBy('file', 'faq-module-AI');

    expect(true)->toBeTrue();
});

test('delete by source type only matches file vector store semantics', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('deleteByQuery')
        ->once()
        ->with(Mockery::on(function (array $params): bool {
            $must = $params['body']['query']['bool']['must'] ?? [];

            return count($must) === 1
                && ($must[0]['term']['sourceType'] ?? null) === 'file';
        }))
        ->andReturn(make_elasticsearch_response(['deleted' => 2]));

    $store = make_rag_vector_store($client);
    $store->deleteBy('file', null);

    expect(true)->toBeTrue();
});

test('similarity search fails fast on elasticsearch errors', function (): void {
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('search')
        ->once()
        ->andThrow(new RuntimeException('cluster unavailable'));

    $store = make_rag_vector_store($client);

    expect(fn () => $store->similaritySearch([0.1, 0.2, 0.3]))
        ->toThrow(VectorStoreException::class, 'Elasticsearch RAG similarity search failed');
});
