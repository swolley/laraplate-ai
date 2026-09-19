<?php

declare(strict_types=1);

use Modules\AI\Services\EmbeddingService;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Splitter\SplitterInterface;

it('returns an embeddings provider via getEmbeddingsProvider', function (): void {
    config()->set('ai.features.embeddings.default_provider', 'sentence_transformers');
    config()->set('ai.providers.sentence_transformers.url', 'http://localhost:8000');

    $service = new EmbeddingService;
    $provider = $service->getEmbeddingsProvider();

    expect($provider)->toBeInstanceOf(EmbeddingsProviderInterface::class);
});

it('embeds several texts in one batched provider call and slices results back per text', function (): void {
    // One chunk per '|'-separated part, so texts produce a variable chunk count.
    $splitter = Mockery::mock(SplitterInterface::class);
    $splitter->shouldReceive('splitDocument')->andReturnUsing(
        static fn (Document $document): array => array_map(
            static fn (string $part): Document => new Document($part),
            explode('|', $document->content),
        ),
    );

    $provider = Mockery::mock(EmbeddingsProviderInterface::class);
    $provider->shouldReceive('embedDocuments')
        ->once() // a single batched call for every chunk of every text
        ->andReturnUsing(static function (array $chunks): array {
            foreach ($chunks as $index => $chunk) {
                $chunk->embedding = [(float) $index];
            }

            return $chunks;
        });

    $service = new EmbeddingService(static fn (): EmbeddingsProviderInterface => $provider, $splitter);

    $result = $service->embedDocumentsBatch(['a|b', 'c']);

    expect($result)->toHaveCount(2)
        ->and($result[0])->toHaveCount(2)
        ->and($result[1])->toHaveCount(1)
        ->and($result[1][0]->embedding)->toBe([2.0]);
});
