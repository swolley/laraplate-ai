<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\EmbeddingsProviderFactory;

test('active resolves the configured multilingual-e5-small profile', function (): void {
    $profile = app(EmbeddingModelRegistry::class)->active();

    expect($profile->key)->toBe('sentence_transformers:intfloat/multilingual-e5-small')
        ->and($profile->queryPrefix)->toBe('query: ')
        ->and($profile->passagePrefix)->toBe('passage: ')
        ->and($profile->dimensions)->toBe(384);
});

test('get throws for an unknown model key', function (): void {
    expect(fn () => app(EmbeddingModelRegistry::class)->get('nope'))
        ->toThrow(InvalidArgumentException::class);
});

it('takes dimensions and similarity from the profile, not from Core', function (): void {
    config([
        'core.search.vector.dimensions' => 999,
        'core.search.vector.similarity' => 'dot_product',
    ]);

    $profile = app(EmbeddingModelRegistry::class)->active();

    expect($profile->dimensions)->toBe(384)
        ->and($profile->similarity)->toBe('cosine');
});

it('rejects a profile with no dimensions', function (mixed $dimensions): void {
    config(['ai.features.embeddings.models.sentence_transformers:broken' => ['dimensions' => $dimensions]]);

    expect(fn () => app(EmbeddingModelRegistry::class)->get('sentence_transformers:broken'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'missing' => [null],
    'zero' => [0],
]);

it('lists the configured profile keys', function (): void {
    expect(app(EmbeddingModelRegistry::class)->keys())->toBe([
        'sentence_transformers:intfloat/multilingual-e5-small',
        'sentence_transformers:all-MiniLM-L6-v2',
    ]);
});

it('derives provider and service model from the key, splitting on the first colon', function (): void {
    config(['ai.features.embeddings.models.ollama:nomic-embed-text:latest' => ['dimensions' => 768]]);

    $registry = app(EmbeddingModelRegistry::class);
    $first = $registry->get('sentence_transformers:intfloat/multilingual-e5-small');
    $second = $registry->get('ollama:nomic-embed-text:latest');

    expect($first->provider)->toBe('sentence_transformers')
        ->and($first->serviceModel)->toBe('intfloat/multilingual-e5-small')
        ->and($second->provider)->toBe('ollama')
        ->and($second->serviceModel)->toBe('nomic-embed-text:latest')
        ->and($second->dimensions)->toBe(768);
});

it('returns the overridden profile while a callback runs and restores the previous one after, also when it throws', function (): void {
    $registry = app(EmbeddingModelRegistry::class);
    $other = 'sentence_transformers:all-MiniLM-L6-v2';

    $inside = $registry->withActive($other, fn (): string => $registry->active()->key);

    expect($inside)->toBe($other)
        ->and($registry->active()->key)->toBe('sentence_transformers:intfloat/multilingual-e5-small');

    expect(fn () => $registry->withActive($other, function (): never {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class);

    expect($registry->active()->key)->toBe('sentence_transformers:intfloat/multilingual-e5-small');
});

it('resolves the provider and service model of the overridden profile in the factory', function (): void {
    config()->set('ai.providers.sentence_transformers.url', 'http://localhost:8000');
    $registry = app(EmbeddingModelRegistry::class);

    $sent = $registry->withActive('sentence_transformers:all-MiniLM-L6-v2', function (): ?string {
        $captured = null;
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('post')
            ->once()
            ->with('embed', Mockery::on(function (array $arg) use (&$captured): bool {
                $captured = $arg['json']['model'] ?? null;

                return true;
            }))
            ->andReturn(new Response(200, [], json_encode(['embeddings' => [array_fill(0, 384, 0.1)]])));

        $provider = EmbeddingsProviderFactory::make();
        $property = new ReflectionProperty($provider, 'client');
        $property->setValue($provider, $client);
        $provider->embedText('hello');

        return $captured;
    });

    expect($sent)->toBe('all-MiniLM-L6-v2');
});

it('rejects a malformed key with an empty provider or service model', function (string $key): void {
    config(['ai.features.embeddings.models' => [$key => ['dimensions' => 384]]]);

    expect(fn () => app(EmbeddingModelRegistry::class)->get($key))
        ->toThrow(InvalidArgumentException::class);
})->with(['no colon' => ['foo'], 'empty provider' => [':x'], 'empty model' => ['x:']]);

it('resolves a dotted profile key when the whole models array is replaced', function (): void {
    config(['ai.features.embeddings.models' => ['ollama:nomic-embed-text:v1.5' => ['dimensions' => 768]]]);

    $profile = app(EmbeddingModelRegistry::class)->get('ollama:nomic-embed-text:v1.5');

    expect($profile->provider)->toBe('ollama')
        ->and($profile->serviceModel)->toBe('nomic-embed-text:v1.5')
        ->and($profile->dimensions)->toBe(768);
});

it('serves the model named by Core search.vector.model', function (): void {
    config()->set('core.search.vector.model', 'voyageai:voyage-3-lite');
    config()->set('ai.providers.sentence_transformers.url', 'http://localhost:8000');
    config()->set('ai.features.embeddings.models', [
        'sentence_transformers:intfloat/multilingual-e5-small' => ['dimensions' => 384],
        'voyageai:voyage-3-lite' => ['dimensions' => 512],
    ]);

    $registry = app(EmbeddingModelRegistry::class);

    expect($registry->activeKey())->toBe('voyageai:voyage-3-lite')
        ->and($registry->active()->dimensions)->toBe(512);
});

it('defaults the active profile to the first configured one when Core names no model', function (?string $model): void {
    config()->set('core.search.vector.model', $model);
    config()->set('ai.providers.sentence_transformers.url', '');
    config()->set('ai.providers.voyageai.api_key', 'k');
    config()->set('ai.features.embeddings.models', [
        'sentence_transformers:intfloat/multilingual-e5-small' => ['dimensions' => 384],
        'voyageai:voyage-3-lite' => ['dimensions' => 512],
    ]);

    expect(app(EmbeddingModelRegistry::class)->active()->key)->toBe('voyageai:voyage-3-lite');
})->with(['null' => [null], 'empty' => ['']]);

it('knows voyageai and sentence_transformers configuration', function (): void {
    $providers = new Modules\AI\Ai\Providers\Models\ProviderConfiguration;

    config()->set('ai.providers.voyageai.api_key', '');
    config()->set('ai.providers.sentence_transformers.url', '');
    expect($providers->isConfigured('voyageai'))->toBeFalse()
        ->and($providers->isConfigured('sentence_transformers'))->toBeFalse();

    config()->set('ai.providers.voyageai.api_key', 'k');
    config()->set('ai.providers.sentence_transformers.url', 'http://x');
    expect($providers->isConfigured('voyageai'))->toBeTrue()
        ->and($providers->isConfigured('sentence_transformers'))->toBeTrue();
});
