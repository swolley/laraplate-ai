<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

/**
 * Text embedding generation (documents and single strings) for RAG and jobs.
 */
interface IEmbeddingService
{
    /**
     * @return Document[]
     */
    public function embedDocument(string $data): array;

    /**
     * @return list<float>
     */
    public function embedText(string $text): array;

    public function getEmbeddingsProvider(): EmbeddingsProviderInterface;
}
