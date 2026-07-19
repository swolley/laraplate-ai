<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Agents;

use Modules\AI\Ai\Embeddings\EmbeddingsProviderFactory;
use Modules\AI\Ai\Providers\ProviderFactory;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
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
    /** @var array<string, MemoryVectorStore> */
    private static array $shared_memory_stores = [];

    public function __construct(
        protected ?string $providerName = null,
        protected ?string $vectorStoreDriver = null,
        protected ?string $vectorStorePath = null,
        protected int $topK = 5,
        protected DocumentationIndexProfile $indexProfile = DocumentationIndexProfile::Developer,
    ) {}

    /**
     * @param  mixed  ...$arguments
     */
    public static function make(mixed ...$arguments): static
    {
        /** @phpstan-ignore new.static */
        return new static(...$arguments);
    }

    /**
     * Clears the in-memory vector store singleton (used when FAQ vector_store driver is "memory" and a full rebuild is requested).
     */
    public static function resetSharedMemoryVectorStore(?DocumentationIndexProfile $profile = null): void
    {
        if ($profile === null) {
            self::$shared_memory_stores = [];

            return;
        }

        unset(self::$shared_memory_stores[$profile->value]);
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
        $configured_driver = config('ai.features.faq.vector_store', 'filesystem');
        $driver = $this->vectorStoreDriver ?? (is_string($configured_driver) ? $configured_driver : 'filesystem');

        return match ($driver) {
            'memory' => self::$shared_memory_stores[$this->indexProfile->value] ??= new MemoryVectorStore($this->topK),
            'elasticsearch' => ElasticsearchRagVectorStore::fromConfig($this->indexProfile, $this->topK),
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
        if (is_string($this->vectorStorePath) && $this->vectorStorePath !== '') {
            return $this->profiledStorePath($this->vectorStorePath);
        }

        $configured_path = config('ai.features.faq.vector_store_path');

        if (is_string($configured_path) && $configured_path !== '') {
            return $this->profiledStorePath($configured_path);
        }

        return $this->profiledStorePath(storage_path('app/ai/faq-vectorstore.store'));
    }

    private function profiledStorePath(string $path): string
    {
        if ($this->indexProfile === DocumentationIndexProfile::Developer) {
            return $path;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $suffix = $extension === '' ? '' : '.' . $extension;
        $base = $suffix === '' ? $path : mb_substr($path, 0, -mb_strlen($suffix));

        return $base . '-user' . $suffix;
    }
}
