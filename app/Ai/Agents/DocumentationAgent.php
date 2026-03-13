<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Agents;

use Modules\AI\Ai\Embeddings\EmbeddingsProviderFactory;
use Modules\AI\Ai\Providers\ProviderFactory;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

/**
 * RAG agent for answering questions using indexed documentation.
 */
class DocumentationAgent extends RAG
{
    private static ?MemoryVectorStore $shared_memory_store = null;

    public function __construct(
        protected ?string $providerName = null,
        protected ?string $vectorStoreDriver = null,
        protected ?string $vectorStorePath = null,
        protected int $topK = 5,
    ) {}

    public static function make(...$arguments): static
    {
        /** @phpstan-ignore new.static */
        return new static(...$arguments);
    }

    protected function provider(): AIProviderInterface
    {
        return ProviderFactory::make($this->providerName);
    }

    protected function instructions(): string
    {
        return <<<'PROMPT'
You are a documentation assistant. Answer questions based on the provided context documents.
If the context doesn't contain enough information, say so honestly.
Always reference specific documents when possible.
Respond in the same language as the question.
PROMPT;
    }

    /**
     * @codeCoverageIgnore
     */
    protected function embeddings(): EmbeddingsProviderInterface
    {
        return EmbeddingsProviderFactory::make();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function vectorStore(): VectorStoreInterface
    {
        $driver = $this->vectorStoreDriver ?? (string) config('ai.features.faq.vector_store', 'filesystem');

        return match ($driver) {
            'memory' => self::$shared_memory_store ??= new MemoryVectorStore($this->topK),
            default => new FileVectorStore(
                directory: dirname($this->getStorePath()),
                topK: $this->topK,
                name: pathinfo($this->getStorePath(), PATHINFO_FILENAME),
            ),
        };
    }

    /**
     * @codeCoverageIgnore
     */
    private function getStorePath(): string
    {
        return $this->vectorStorePath
            ?? (string) config('ai.features.faq.vector_store_path')
            ?: storage_path('app/ai/faq-vectorstore.store');
    }
}
