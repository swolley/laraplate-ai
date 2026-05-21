<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\EmbeddingsProviderFactory;
use Modules\AI\Ai\Embeddings\SentenceTransformersEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\MistralEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider;

it('creates an OpenAI embeddings provider', function (): void {
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('ai.providers.openai.model', 'text-embedding-3-small');

    $provider = EmbeddingsProviderFactory::make('openai');

    expect($provider)->toBeInstanceOf(OpenAIEmbeddingsProvider::class);
});

it('creates an Ollama embeddings provider', function (): void {
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');
    config()->set('ai.providers.ollama.model', 'nomic-embed-text');

    $provider = EmbeddingsProviderFactory::make('ollama');

    expect($provider)->toBeInstanceOf(OllamaEmbeddingsProvider::class);
});

it('creates a Mistral embeddings provider', function (): void {
    config()->set('ai.providers.mistral.api_key', 'test-key');
    config()->set('ai.providers.mistral.model', 'mistral-embed');

    $provider = EmbeddingsProviderFactory::make('mistral');

    expect($provider)->toBeInstanceOf(MistralEmbeddingsProvider::class);
});

it('creates a VoyageAI embeddings provider', function (): void {
    config()->set('ai.providers.voyageai.api_key', 'test-key');
    config()->set('ai.providers.voyageai.model', 'voyage-3-lite');

    $provider = EmbeddingsProviderFactory::make('voyageai');

    expect($provider)->toBeInstanceOf(VoyageEmbeddingsProvider::class);
});

it('creates a SentenceTransformers embeddings provider', function (): void {
    config()->set('ai.providers.sentence_transformers.url', 'http://localhost:8000');

    $provider = EmbeddingsProviderFactory::make('sentence_transformers');

    expect($provider)->toBeInstanceOf(SentenceTransformersEmbeddingsProvider::class);
});

it('also accepts hyphenated sentence-transformers key', function (): void {
    config()->set('ai.providers.sentence_transformers.url', 'http://localhost:8000');

    $provider = EmbeddingsProviderFactory::make('sentence-transformers');

    expect($provider)->toBeInstanceOf(SentenceTransformersEmbeddingsProvider::class);
});

it('throws exception for unsupported embeddings provider', function (): void {
    EmbeddingsProviderFactory::make('non-existent');
})->throws(Exception::class, 'Unsupported embeddings provider: non-existent');

it('uses default provider from config when none specified', function (): void {
    config()->set('ai.features.embeddings.default_provider', 'sentence_transformers');
    config()->set('ai.providers.sentence_transformers.url', 'http://localhost:8000');

    $provider = EmbeddingsProviderFactory::make();

    expect($provider)->toBeInstanceOf(SentenceTransformersEmbeddingsProvider::class);
});
