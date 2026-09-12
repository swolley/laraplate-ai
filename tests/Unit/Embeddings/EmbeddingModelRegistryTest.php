<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;

test('active resolves the configured multilingual-e5-small profile', function (): void {
    $profile = app(EmbeddingModelRegistry::class)->active();

    expect($profile->key)->toBe('multilingual-e5-small')
        ->and($profile->queryPrefix)->toBe('query: ')
        ->and($profile->passagePrefix)->toBe('passage: ')
        ->and($profile->dimensions)->toBe((int) config('search.vector.dimensions'));
});

test('get throws for an unknown model key', function (): void {
    expect(fn () => app(EmbeddingModelRegistry::class)->get('nope'))
        ->toThrow(InvalidArgumentException::class);
});
