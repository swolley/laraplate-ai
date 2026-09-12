<?php

declare(strict_types=1);

use Modules\AI\Services\EmbeddingService;
use Modules\AI\Services\SearchEmbedder;
use Modules\AI\Tests\Stubs\Embeddings\FixedChunkSplitter;
use Modules\AI\Tests\Stubs\Embeddings\RecordingEmbeddingsProvider;

test('SearchEmbedder::embed prepends the active profile query prefix', function (): void {
    config()->set('ai.features.embeddings.active', 'multilingual-e5-small');

    $provider = new RecordingEmbeddingsProvider;
    $embeddingService = new EmbeddingService(fn () => $provider);
    $searchEmbedder = new SearchEmbedder($embeddingService);

    $searchEmbedder->embed('festival');

    expect($provider->textsSent)->toBe(['query: festival']);
});

test('EmbeddingService::embedDocument prepends the active profile passage prefix', function (): void {
    config()->set('ai.features.embeddings.active', 'multilingual-e5-small');

    $provider = new RecordingEmbeddingsProvider;
    $embeddingService = new EmbeddingService(fn () => $provider);

    $embeddingService->embedDocument('some body text');

    expect($provider->textsSent)->toHaveCount(1)
        ->and($provider->textsSent[0])->toStartWith('passage: ');
});

test('EmbeddingService::embedDocument prefixes every chunk when the body is split', function (): void {
    config()->set('ai.features.embeddings.active', 'multilingual-e5-small');

    $provider = new RecordingEmbeddingsProvider;
    $embeddingService = new EmbeddingService(fn () => $provider, new FixedChunkSplitter);

    $embeddingService->embedDocument('first half content here and second half content there');

    expect($provider->textsSent)->toHaveCount(2);

    foreach ($provider->textsSent as $text) {
        expect($text)->toStartWith('passage: ');
    }
});

test('EmbeddingService::embedDocument does not prefix when the active profile has no passage prefix', function (): void {
    config()->set('ai.features.embeddings.active', 'all-MiniLM-L6-v2');

    $provider = new RecordingEmbeddingsProvider;
    $embeddingService = new EmbeddingService(fn () => $provider);

    $embeddingService->embedDocument('some body text');

    expect($provider->textsSent)->toBe(['some body text']);
});
