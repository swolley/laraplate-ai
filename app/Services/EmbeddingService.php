<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Closure;
use Modules\AI\Ai\Embeddings\EmbeddingsProviderFactory;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Services\Documentation\Chunking\SplitterFactory;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Splitter\SplitterInterface;
use Override;

final readonly class EmbeddingService implements IEmbeddingService
{
    /**
     * @param  Closure(): EmbeddingsProviderInterface|null  $providerFactory  Optional factory for testing
     */
    public function __construct(
        private ?Closure $providerFactory = null,
        private ?SplitterInterface $splitter = null,
    ) {}

    /**
     * Generate embeddings for a document (with splitting for long texts).
     *
     * @return Document[]
     */
    #[Override]
    public function embedDocument(string $data): array
    {
        $content = preg_replace("/\n|\t/", ' ', $data);
        $content = preg_replace("/\s+/", ' ', (string) $content);
        $content = mb_trim((string) $content);

        $document = new Document($content);
        $document->sourceType = 'inline';
        $document->sourceName = 'document';

        $splitter = $this->splitter ?? SplitterFactory::make();
        $chunks = $splitter->splitDocument($document);

        $generator = $this->getProvider();

        return $generator->embedDocuments($chunks);
    }

    /**
     * Generate embedding for a simple text string.
     *
     * @return list<float>
     */
    #[Override]
    public function embedText(string $text): array
    {
        return array_values($this->getProvider()->embedText($text));
    }

    /**
     * Get the configured embedding provider for use by other services (e.g. RAG).
     */
    #[Override]
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
