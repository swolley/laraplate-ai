<?php

declare(strict_types=1);

use Elastic\Elasticsearch\Client;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Console\CreateRagElasticsearchIndexCommand;
use Modules\AI\Services\DocumentationService;

test('has documents returns false when count throws', function (): void {
    config()->set('ai.features.faq.elasticsearch.developer_index', 'test-index');
    config()->set('ai.features.faq.elasticsearch.user_index', 'test-user-index');
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('count')
        ->once()
        ->with(['index' => 'test-index'])
        ->andThrow(new RuntimeException('index_not_found_exception'));

    $store = new ElasticsearchRagVectorStore($client, DocumentationIndexProfile::Developer, 5, 384);

    expect($store->hasDocuments())->toBeFalse();
});

test('has documents returns true when count is positive', function (): void {
    config()->set('ai.features.faq.elasticsearch.developer_index', 'test-index');
    config()->set('ai.features.faq.elasticsearch.user_index', 'test-user-index');
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('count')
        ->once()
        ->with(['index' => 'test-index'])
        ->andReturn(make_elasticsearch_response(['count' => 3]));

    $store = new ElasticsearchRagVectorStore($client, DocumentationIndexProfile::Developer, 5, 384);

    expect($store->hasDocuments())->toBeTrue();
});

test('documentation service is unavailable when filesystem store file is missing', function (): void {
    config()->set('ai.features.faq.enabled', true);
    config()->set('ai.features.faq.vector_store', 'filesystem');
    config()->set('ai.features.faq.vector_store_path', storage_path('framework/testing/missing-rag-vectorstore.store'));

    expect(app(DocumentationService::class)->isAvailable())->toBeFalse();
});

test('create rag elasticsearch index command fails when faq is disabled', function (): void {
    config()->set('ai.features.faq.enabled', false);

    $this->artisan(CreateRagElasticsearchIndexCommand::class)
        ->expectsOutputToContain('FAQ/RAG is disabled')
        ->assertExitCode(1);
});

test('create rag elasticsearch index command rejects an invalid profile', function (): void {
    config()->set('ai.features.faq.enabled', true);

    $this->artisan(CreateRagElasticsearchIndexCommand::class, ['--profile' => 'unsafe'])
        ->expectsOutputToContain('Invalid profile')
        ->assertExitCode(1);
});

test('documentation service reports unavailable for elasticsearch when the cluster has no documents', function (): void {
    config()->set('ai.features.faq.enabled', true);
    config()->set('ai.features.faq.vector_store', 'elasticsearch');
    config()->set('ai.features.faq.elasticsearch.developer_index', 'missing-rag-index-for-tests');
    config()->set('ai.features.faq.elasticsearch.user_index', 'missing-rag-user-index-for-tests');

    expect(app(DocumentationService::class)->isAvailable())->toBeFalse();
});

test('documentation service prefers incremental reindex on the memory driver', function (): void {
    $service = new DocumentationService;
    $method = new ReflectionMethod($service, 'shouldUseIncrementalReindex');

    expect($method->invoke($service, 'memory'))->toBeTrue();
});
