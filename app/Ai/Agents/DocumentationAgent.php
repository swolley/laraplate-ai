<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Agents;

use Modules\AI\Ai\Embeddings\EmbeddingsProviderFactory;
use Modules\AI\Ai\Providers\AiModelChoice;
use Modules\AI\Ai\Providers\ProviderFactory;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Ai\Rag\FaqVectorStoreConfig;
use Modules\AI\Enums\AiModelFeature;
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
    /**
     * @var array<string, MemoryVectorStore>
     */
    private static array $shared_memory_stores = [];

    public function __construct(
        protected ?string $providerName = null,
        protected ?string $vectorStoreDriver = null,
        protected ?string $vectorStorePath = null,
        protected int $topK = 5,
        protected DocumentationIndexProfile $indexProfile = DocumentationIndexProfile::Developer,
    ) {
        // RAG extends NeuronAI's Workflow, whose constructor initialises the workflow
        // executor: without it, chat() fails before reaching the provider.
        parent::__construct();
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
        if ($this->providerName !== null) {
            return ProviderFactory::make($this->providerName);
        }

        $choice = AiModelChoice::forFeature(AiModelFeature::Faq);

        return ProviderFactory::make($choice->provider, $choice->model);
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
        $driver = $this->vectorStoreDriver ?? FaqVectorStoreConfig::driver();

        return match ($driver) {
            'memory' => self::$shared_memory_stores[$this->indexProfile->value] ??= new MemoryVectorStore($this->topK),
            'elasticsearch' => ElasticsearchRagVectorStore::fromConfig($this->indexProfile, $this->topK),
            default => $this->fileVectorStore(),
        };
    }

    /**
     * The store writes the file that {@see FaqVectorStoreConfig::file()} names, with the extension of
     * that name: Neuron joins its own `.store` otherwise, and the file would not be the one the service
     * looks for.
     *
     * @codeCoverageIgnore
     */
    private function fileVectorStore(): FileVectorStore
    {
        $file = FaqVectorStoreConfig::file($this->indexProfile, $this->vectorStorePath);

        return new FileVectorStore(
            directory: $file->directory(),
            topK: $this->topK,
            name: $file->name(),
            ext: $file->extension(),
        );
    }
}
