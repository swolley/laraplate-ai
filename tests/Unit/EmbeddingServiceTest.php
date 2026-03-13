<?php

declare(strict_types=1);

use Modules\AI\Services\EmbeddingService;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

it('returns an embeddings provider via getEmbeddingsProvider', function (): void {
    config()->set('ai.features.embeddings.default_provider', 'sentence_transformers');
    config()->set('ai.providers.sentence_transformers.url', 'http://localhost:8000');

    $service = new EmbeddingService;
    $provider = $service->getEmbeddingsProvider();

    expect($provider)->toBeInstanceOf(EmbeddingsProviderInterface::class);
});
