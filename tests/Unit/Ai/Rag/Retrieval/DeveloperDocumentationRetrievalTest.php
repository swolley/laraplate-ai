<?php

declare(strict_types=1);

use Modules\AI\Ai\Rag\Retrieval\DeveloperDocumentationRetrieval;
use Modules\AI\Contracts\IEmbeddingService;
use NeuronAI\RAG\Document;

it('returns developer index hits without an ACL or permission gate', function (): void {
    config()->set('ai.features.embeddings.active', 'multilingual-e5-small');
    config()->set('ai.features.embeddings.models.multilingual-e5-small.query_prefix', 'query: ');

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldReceive('embedText')
        ->once()
        ->with('query: How do I register a module?')
        ->andReturn([0.1, 0.2, 0.3]);

    $hit = new Document('Register the module in module.json.');
    $hit->sourceName = 'core/modules/registration';
    $hit->metadata = ['audience' => 'developer', 'module' => 'Core', 'locale' => 'en'];
    $hit->setScore(0.87);

    $retrieval = new DeveloperDocumentationRetrieval(
        embedding_service: $embedding_service,
        search: static function (array $embedding) use ($hit): array {
            expect($embedding)->toBe([0.1, 0.2, 0.3]);

            return [$hit];
        },
    );

    $documents = $retrieval->retrieve('How do I register a module?');

    expect($documents)->toHaveCount(1)
        ->and($documents[0]->sourceName)->toBe('core/modules/registration')
        ->and($documents[0]->getScore())->toBe(0.87);
});

it('rejects a blank developer question', function (): void {
    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldReceive('embedText')->never();

    $retrieval = new DeveloperDocumentationRetrieval($embedding_service);

    expect(fn (): array => $retrieval->retrieve('   '))->toThrow(InvalidArgumentException::class);
});

it('fails closed when the developer search is unavailable', function (): void {
    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldReceive('embedText')->once()->andReturn([0.1, 0.2, 0.3]);

    $retrieval = new DeveloperDocumentationRetrieval(
        embedding_service: $embedding_service,
        search: static fn (): never => throw new RuntimeException('developer index down'),
    );

    expect(fn (): array => $retrieval->retrieve('anything'))
        ->toThrow(RuntimeException::class, 'Developer documentation retrieval is unavailable.');
});
