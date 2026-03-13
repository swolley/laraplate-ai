<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Closure;
use Modules\AI\Ai\Embeddings\EmbeddingsProviderFactory;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Splitter\SentenceTextSplitter;

final readonly class EmbeddingService
{
    /**
     * @param  Closure(): EmbeddingsProviderInterface|null  $providerFactory  Optional factory for testing
     */
    public function __construct(
        private ?Closure $providerFactory = null,
    ) {}

    /**
     * Generate embeddings for a document (with splitting for long texts).
     *
     * @return Document[]
     */
    public function embedDocument(string $data): array
    {
        $content = preg_replace("/\n|\t/", ' ', $data);
        $content = preg_replace("/\s+/", ' ', (string) $content);
        $content = mb_trim((string) $content);

        $document = new Document($content);
        $document->sourceType = 'inline';
        $document->sourceName = 'document';

        $splitter = new SentenceTextSplitter;
        $chunks = $splitter->splitDocument($document);

        $generator = $this->getProvider();

        return $generator->embedDocuments($chunks);
    }

    /**
     * Generate embedding for a simple text string.
     *
     * @return float[]
     */
    public function embedText(string $text): array
    {
        return $this->getProvider()->embedText($text);
    }

    /**
     * Get the configured embedding provider for use by other services (e.g. RAG).
     */
    public function getEmbeddingsProvider(): EmbeddingsProviderInterface
    {
        return $this->getProvider();
    }

    private function getProvider(): EmbeddingsProviderInterface
    {
        $factory = $this->providerFactory ?? EmbeddingsProviderFactory::make(...);

        return $factory();
    }
}
