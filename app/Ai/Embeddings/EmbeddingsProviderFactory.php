<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use Exception;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Embeddings\MistralEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider;

/**
 * Factory for creating NeuronAI embeddings provider instances from application config.
 */
final class EmbeddingsProviderFactory
{
    public static function make(?string $provider = null): EmbeddingsProviderInterface
    {
        $provider ??= (string) config('ai.features.embeddings.default_provider', 'sentence_transformers');

        return match ($provider) {
            'openai' => self::createOpenAI(),
            'ollama' => self::createOllama(),
            'mistral' => self::createMistral(),
            'voyageai' => self::createVoyage(),
            'sentence-transformers', 'sentence_transformers' => self::createSentenceTransformers(),
            default => throw new Exception("Unsupported embeddings provider: {$provider}"),
        };
    }

    private static function createOpenAI(): OpenAIEmbeddingsProvider
    {
        $api_key = (string) config('ai.providers.openai.api_key');
        $model = (string) config('ai.providers.openai.model', 'text-embedding-3-small');

        return new OpenAIEmbeddingsProvider(
            key: $api_key,
            model: $model,
        );
    }

    private static function createOllama(): OllamaEmbeddingsProvider
    {
        $url = (string) config('ai.providers.ollama.api_url', 'http://localhost:11434/api');
        $model = (string) config('ai.providers.ollama.model', 'nomic-embed-text');

        return new OllamaEmbeddingsProvider(
            model: $model,
            url: $url . '/api',
        );
    }

    private static function createMistral(): MistralEmbeddingsProvider
    {
        $api_key = (string) config('ai.providers.mistral.api_key');
        $model = (string) config('ai.providers.mistral.model', 'mistral-embed');

        return new MistralEmbeddingsProvider(
            key: $api_key,
            model: $model,
        );
    }

    private static function createVoyage(): VoyageEmbeddingsProvider
    {
        $api_key = (string) config('ai.providers.voyageai.api_key');
        $model = (string) config('ai.providers.voyageai.model', 'voyage-3-lite');

        return new VoyageEmbeddingsProvider(
            key: $api_key,
            model: $model,
        );
    }

    private static function createSentenceTransformers(): SentenceTransformersEmbeddingsProvider
    {
        return new SentenceTransformersEmbeddingsProvider(
            url: (string) config('ai.providers.sentence_transformers.url', 'http://localhost:8000'),
            api_key: config('ai.providers.sentence_transformers.api_key'),
        );
    }
}
