<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use function ai_config_int;
use function ai_config_nullable_string;
use function ai_config_string;

use InvalidArgumentException;
use Modules\Core\Exceptions\ConfigurationException;
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
        $provider ??= self::registry()->active()->provider;

        return match ($provider) {
            'openai' => self::createOpenAI(),
            'ollama' => self::createOllama(),
            'mistral' => self::createMistral(),
            'voyageai' => self::createVoyage(),
            'sentence-transformers', 'sentence_transformers' => self::createSentenceTransformers(),
            default => throw new InvalidArgumentException("Unsupported embeddings provider: {$provider}"),
        };
    }

    private static function createOpenAI(): OpenAIEmbeddingsProvider
    {
        return new OpenAIEmbeddingsProvider(
            key: ai_config_string('ai.providers.openai.api_key'),
            model: ai_config_string('ai.providers.openai.model', 'text-embedding-3-small'),
        );
    }

    private static function createOllama(): OllamaEmbeddingsProvider
    {
        $url = ai_config_string('ai.providers.ollama.api_url');
        throw_if($url === '', ConfigurationException::class, 'Ollama API URL is not configured');

        return new OllamaEmbeddingsProvider(
            model: ai_config_string('ai.providers.ollama.model', 'nomic-embed-text'),
            url: mb_rtrim($url, '/') . '/api',
        );
    }

    private static function createMistral(): MistralEmbeddingsProvider
    {
        return new MistralEmbeddingsProvider(
            key: ai_config_string('ai.providers.mistral.api_key'),
            model: ai_config_string('ai.providers.mistral.model', 'mistral-embed'),
        );
    }

    private static function createVoyage(): VoyageEmbeddingsProvider
    {
        return new VoyageEmbeddingsProvider(
            key: ai_config_string('ai.providers.voyageai.api_key'),
            model: ai_config_string('ai.providers.voyageai.model', 'voyage-3-lite'),
        );
    }

    private static function createSentenceTransformers(): SentenceTransformersEmbeddingsProvider
    {
        return new SentenceTransformersEmbeddingsProvider(
            url: ai_config_string('ai.providers.sentence_transformers.url', 'http://localhost:8000'),
            api_key: ai_config_nullable_string('ai.providers.sentence_transformers.api_key'),
            timeout: ai_config_int('ai.providers.sentence_transformers.timeout', 30),
            batch_size: ai_config_int('ai.providers.sentence_transformers.batch_size', 32),
            model: self::activeServiceModel(),
        );
    }

    private static function registry(): EmbeddingModelRegistry
    {
        return resolve(EmbeddingModelRegistry::class);
    }

    /**
     * The active embedding profile's service model, sent per request so the
     * embedding service (which is multi-model) uses the model Laraplate expects.
     */
    private static function activeServiceModel(): ?string
    {
        $model = self::registry()->active()->serviceModel;

        return $model === '' ? null : $model;
    }
}
