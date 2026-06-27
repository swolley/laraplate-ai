<?php

declare(strict_types=1);

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Console\CreateRagElasticsearchIndexCommand;
use Modules\AI\Services\DocumentationService;

/**
 * @param  array<string, mixed>|bool  $payload
 */
function make_es_bool_response(array|bool $payload): Elasticsearch
{
    $body = is_bool($payload) ? $payload : $payload;

    return new Elasticsearch(
        new GuzzleHttp\Psr7\Response(200, [], (string) json_encode($body, JSON_THROW_ON_ERROR)),
    );
}

test('has documents returns false when index does not exist', function (): void {
    $client = Mockery::mock(Client::class);
    $indices = Mockery::mock();
    $indices->shouldReceive('exists')
        ->once()
        ->andReturn(make_es_bool_response(false));

    $client->shouldReceive('indices')->andReturn($indices);

    $store = new ElasticsearchRagVectorStore($client, 'test-index', 5, 384);

    expect($store->hasDocuments())->toBeFalse();
});

test('has documents returns true when index exists and count is positive', function (): void {
    $client = Mockery::mock(Client::class);
    $indices = Mockery::mock();
    $indices->shouldReceive('exists')
        ->once()
        ->andReturn(make_es_bool_response(true));

    $client->shouldReceive('indices')->andReturn($indices);
    $client->shouldReceive('count')
        ->once()
        ->with(['index' => 'test-index'])
        ->andReturn(make_es_bool_response(['count' => 3]));

    $store = new ElasticsearchRagVectorStore($client, 'test-index', 5, 384);

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
