<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Ai\Rag\RagIndexRebuilder;

const RAG_ACTIVE = 'sentence_transformers:intfloat/multilingual-e5-small';
const RAG_WIDE = 'sentence_transformers:wide-768';

beforeEach(function (): void {
    config()->set('ai.features.embeddings.models.' . RAG_WIDE, ['dimensions' => 768]);
    config()->set('ai.features.embeddings.active', RAG_ACTIVE);
    config()->set('ai.features.faq.enabled', true);
    config()->set('ai.features.faq.vector_store', 'elasticsearch');
});

it('sizes the RAG vectors from the active embedding profile', function (): void {
    expect(ElasticsearchRagVectorStore::activeDimensions())->toBe(384)
        ->and(app(EmbeddingModelRegistry::class)->withActive(RAG_WIDE, static fn (): int => ElasticsearchRagVectorStore::activeDimensions()))->toBe(768)
        ->and(config()->has('ai.features.faq.elasticsearch.embedding_dims'))->toBeFalse();
});

it('recreates the RAG indexes and reindexes the documentation with the target profile in force', function (): void {
    $seen = [];
    Artisan::shouldReceive('call')
        ->twice()
        ->andReturnUsing(static function (string $command, array $parameters) use (&$seen): int {
            $seen[] = [$command, $parameters, ElasticsearchRagVectorStore::activeDimensions()];

            return 0;
        });

    app(RagIndexRebuilder::class)->rebuild(app(EmbeddingModelRegistry::class)->get(RAG_WIDE));

    expect($seen)->toBe([
        ['ai:create-rag-index', ['--profile' => 'all', '--force' => true], 768],
        ['ai:index-rag-docs', ['--profile' => 'all', '--full' => true], 768],
    ])
        ->and(ElasticsearchRagVectorStore::activeDimensions())->toBe(384);
});

it('fails with the command output when a RAG command fails', function (): void {
    Artisan::shouldReceive('call')->once()->andReturn(1);
    Artisan::shouldReceive('output')->once()->andReturn('cluster unreachable');

    expect(fn () => app(RagIndexRebuilder::class)->rebuild(app(EmbeddingModelRegistry::class)->get(RAG_WIDE)))
        ->toThrow(RuntimeException::class, 'cluster unreachable');
});

it('does nothing when the documentation is not kept in Elasticsearch', function (string $key, mixed $value): void {
    config()->set($key, $value);
    Artisan::shouldReceive('call')->never();

    app(RagIndexRebuilder::class)->rebuild(app(EmbeddingModelRegistry::class)->get(RAG_WIDE));
})->with([
    'faq disabled' => ['ai.features.faq.enabled', false],
    'filesystem store' => ['ai.features.faq.vector_store', 'filesystem'],
]);
