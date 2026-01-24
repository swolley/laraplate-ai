<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Str;
use JsonException;
use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\DocumentSplitter\DocumentSplitter;
use LLPhant\Embeddings\EmbeddingFormatter\EmbeddingFormatter;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;
use LLPhant\Embeddings\EmbeddingGenerator\Mistral\MistralEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\Ollama\OllamaEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3LargeEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3SmallEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAIADA002EmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\VoyageAI\Voyage3EmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\VoyageAI\Voyage3LargeEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\VoyageAI\Voyage3LiteEmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\VoyageAI\VoyageCode2EmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\VoyageAI\VoyageCode3EmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\VoyageAI\VoyageFinance2EmbeddingGenerator;
use LLPhant\Embeddings\EmbeddingGenerator\VoyageAI\VoyageLaw2EmbeddingGenerator;
use LLPhant\OllamaConfig;
use LLPhant\OpenAIConfig;
use LLPhant\VoyageAIConfig;
use Modules\AI\Ai\SentenceTransformersConfig;
use Modules\AI\Ai\SentenceTransformersEmbeddingGenerator;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

final class EmbeddingService
{
    /**
     * Generate embeddings for a document (with splitting for long texts).
     *
     * @throws BindingResolutionException
     * @throws ClientExceptionInterface
     * @throws JsonException
     * @throws Exception
     * @throws GuzzleException
     * @throws RuntimeException
     *
     * @return Document[]
     */
    public function embedDocument(string $data): array
    {
        $document = new Document();
        $document->content = Str::of($data)->replaceMatches("/\n|\t/", ' ')->replaceMatches("/\s+/", ' ')->trim()->toString();

        $splitDocuments = DocumentSplitter::splitDocument($document);
        $formattedDocuments = EmbeddingFormatter::formatEmbeddings($splitDocuments);

        $generator = $this->getGenerator();

        if (! $generator instanceof EmbeddingGeneratorInterface) {
            return [];
        }

        return $generator->embedDocuments($formattedDocuments);
    }

    /**
     * Generate embedding for a simple text string.
     *
     * @throws BindingResolutionException
     *
     * @return float[]
     */
    public function embedText(string $text): array
    {
        $generator = $this->getGenerator();

        if (! $generator instanceof EmbeddingGeneratorInterface) {
            return [];
        }

        return $generator->embedText($text);
    }

    private function getGenerator(): ?EmbeddingGeneratorInterface
    {
        switch (config('ai.features.embeddings.provider')) {
            case 'openai':
                $config = new OpenAIConfig(config('ai.providers.openai.api_key'));

                return match (config('ai.providers.openai.model') ?: config('ai.providers.openai.openai_model')) {
                    'text-embedding-3-large' => new OpenAI3LargeEmbeddingGenerator($config),
                    'text-embedding-ada-002' => new OpenAIADA002EmbeddingGenerator($config),
                    default => new OpenAI3SmallEmbeddingGenerator($config),
                };

            case 'ollama':
                $config = new OllamaConfig();

                if (config('ai.providers.ollama.api_url')) {
                    $config->url = config('ai.providers.ollama.api_url');
                }

                $config->model = match (config('ai.providers.ollama.model')) {
                    'nomic-embed-large' => 'nomic-embed-large',
                    default => 'nomic-embed-text',
                };

                return new OllamaEmbeddingGenerator($config);
            case 'voyageai':
                $config = new VoyageAIConfig(config('ai.providers.voyageai.api_key'));

                return match (config('ai.providers.voyageai.model')) {
                    'voyage-3' => new Voyage3EmbeddingGenerator($config),
                    'voyage-3-large' => new Voyage3LargeEmbeddingGenerator($config),
                    'voyage-code-2' => new VoyageCode2EmbeddingGenerator($config),
                    'voyage-code-3' => new VoyageCode3EmbeddingGenerator($config),
                    'voyage-finance-2' => new VoyageFinance2EmbeddingGenerator($config),
                    'voyage-law-2' => new VoyageLaw2EmbeddingGenerator($config),
                    default => new Voyage3LiteEmbeddingGenerator($config),
                };

            case 'mistral':
                $config = new OpenAIConfig(config('ai.providers.mistral.api_key'));

                return new MistralEmbeddingGenerator($config);
            case 'sentence-transformers':
            case 'sentence_transformers':
                $config = new SentenceTransformersConfig(config('ai.providers.sentence_transformers.api_key'), config('ai.providers.sentence_transformers.url'));

                return new SentenceTransformersEmbeddingGenerator($config);
            default:
                return null;
        }
    }
}
