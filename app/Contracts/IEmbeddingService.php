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
     * Embed several texts in a single batched call to the provider, returning
     * each input's chunk documents (with embeddings) aligned to the input order.
     *
     * @param  list<string>  $texts
     * @return list<Document[]>
     */
    public function embedDocumentsBatch(array $texts): array;

    /**
     * @return list<float>
     */
    public function embedText(string $text): array;

    public function getEmbeddingsProvider(): EmbeddingsProviderInterface;
}
