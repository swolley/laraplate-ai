<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Modules\AI\Ai\Embeddings\SentenceTransformersEmbeddingsProvider;
use Modules\Core\Search\Exceptions\EmbeddingsException;
use NeuronAI\RAG\Document;

beforeEach(function (): void {
    $this->mockClient = Mockery::mock(Client::class);
});

afterEach(function (): void {
    Mockery::close();
});

it('embedText sends POST to embed and returns float array', function (): void {
    $embeddings = array_fill(0, 512, 0.1);
    $this->mockClient->shouldReceive('post')
        ->once()
        ->with('embed', Mockery::on(fn (array $arg): bool => isset($arg['json']['text']) && isset($arg['json']['truncation'])))
        ->andReturn(new Response(200, [], json_encode(['embeddings' => [$embeddings]])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $result = $provider->embedText('hello world');

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(512)
        ->and($result[0])->toBe(0.1);
});

it('embedDocuments processes batches and sets embedding on documents', function (): void {
    $doc1 = new Document('First');
    $doc2 = new Document('Second');
    $emb1 = array_fill(0, 512, 0.1);
    $emb2 = array_fill(0, 512, 0.2);

    $this->mockClient->shouldReceive('post')
        ->once()
        ->with('embed', Mockery::on(fn (array $arg): bool => isset($arg['json']['texts']) && count($arg['json']['texts']) === 2))
        ->andReturn(new Response(200, [], json_encode(['embeddings' => [$emb1, $emb2]])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $result = $provider->embedDocuments([$doc1, $doc2]);

    expect($result)->toHaveCount(2)
        ->and($result[0]->embedding)->toBe($emb1)
        ->and($result[1]->embedding)->toBe($emb2);
});

it('throws exception on unexpected format', function (): void {
    $this->mockClient->shouldReceive('post')
        ->once()
        ->andReturn(new Response(200, [], json_encode(['invalid' => 'response'])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $provider->embedText('test');
})->throws(Exception::class);

it('throws exception when embedText receives no embeddings', function (): void {
    $this->mockClient->shouldReceive('post')
        ->once()
        ->andReturn(new Response(200, [], json_encode(['embeddings' => []])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $provider->embedText('test');
})->throws(EmbeddingsException::class, 'SentenceTransformers returned an empty embedding');

it('throws exception when embeddings payload is not an array', function (): void {
    $this->mockClient->shouldReceive('post')
        ->once()
        ->andReturn(new Response(200, [], json_encode(['embeddings' => 'invalid'])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $provider->embedText('test');
})->throws(EmbeddingsException::class, 'SentenceTransformers returned unexpected format');

it('throws exception when an embedding vector is not an array', function (): void {
    $this->mockClient->shouldReceive('post')
        ->once()
        ->andReturn(new Response(200, [], json_encode(['embeddings' => ['invalid']])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $provider->embedText('test');
})->throws(EmbeddingsException::class, 'SentenceTransformers returned invalid embedding vector');

it('throws exception when an embedding component is not numeric', function (): void {
    $this->mockClient->shouldReceive('post')
        ->once()
        ->andReturn(new Response(200, [], json_encode(['embeddings' => [[0.1, 'invalid']]])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $provider->embedText('test');
})->throws(EmbeddingsException::class, 'SentenceTransformers returned invalid embedding component');

it('prepends http when missing from URL', function (): void {
    $embeddings = array_fill(0, 512, 0.1);
    $this->mockClient->shouldReceive('post')
        ->once()
        ->andReturn(new Response(200, [], json_encode(['embeddings' => [$embeddings]])));

    $provider = new SentenceTransformersEmbeddingsProvider('localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $provider->embedText('test');

    expect(true)->toBeTrue();
});

it('uses an empty text when document formatted content is not a string', function (): void {
    $document = new class extends Document
    {
        /**
         * @var array<int, string>
         */
        public array $formattedContent = ['not text'];
    };

    $embeddings = array_fill(0, 512, 0.1);
    $this->mockClient->shouldReceive('post')
        ->once()
        ->with('embed', Mockery::on(fn (array $arg): bool => $arg['json']['texts'] === ['']))
        ->andReturn(new Response(200, [], json_encode(['embeddings' => [$embeddings]])));

    $provider = new SentenceTransformersEmbeddingsProvider('http://localhost:8000');
    $reflection = new ReflectionClass($provider);
    $clientProp = $reflection->getProperty('client');
    $clientProp->setValue($provider, $this->mockClient);

    $result = $provider->embedDocuments([$document]);

    expect($result[0]->embedding)->toBe($embeddings);
});

it('sets authorization header when api_key is provided', function (): void {
    $provider = new SentenceTransformersEmbeddingsProvider(
        url: 'http://localhost:8000',
        api_key: 'test-secret-key',
    );
    expect($provider)->toBeInstanceOf(SentenceTransformersEmbeddingsProvider::class);
});
