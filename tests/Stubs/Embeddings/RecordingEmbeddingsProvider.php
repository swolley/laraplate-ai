<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Embeddings;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

/**
 * Fake embeddings provider that records the exact text handed to it, mirroring
 * how {@see \Modules\AI\Ai\Embeddings\SentenceTransformersEmbeddingsProvider}
 * reads `formattedContent ?? content` for documents. Used to assert that
 * query/passage prefixes reach the provider boundary.
 */
final class RecordingEmbeddingsProvider implements EmbeddingsProviderInterface
{
    /**
     * @var list<string>
     */
    public array $textsSent = [];

    public function embedText(string $text): array
    {
        $this->textsSent[] = $text;

        return [0.1, 0.2, 0.3];
    }

    public function embedDocument(Document $document): Document
    {
        $text = $document->formattedContent ?? $document->content;
        $this->textsSent[] = is_string($text) ? $text : $document->content;
        $document->embedding = [0.1, 0.2, 0.3];

        return $document;
    }

    /**
     * @param  Document[]  $documents
     * @return Document[]
     */
    public function embedDocuments(array $documents): array
    {
        return array_map($this->embedDocument(...), $documents);
    }
}
