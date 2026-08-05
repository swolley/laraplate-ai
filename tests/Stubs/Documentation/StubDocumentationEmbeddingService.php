<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Documentation;

use BadMethodCallException;
use Modules\AI\Contracts\IEmbeddingService;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

final class StubDocumentationEmbeddingService implements IEmbeddingService
{
    /**
     * @return array<int, mixed>
     */
    public function embedDocument(string $data): array
    {
        return [];
    }

    /**
     * @return list<float>
     */
    public function embedText(string $text): array
    {
        return [(float) crc32($text)];
    }

    public function getEmbeddingsProvider(): EmbeddingsProviderInterface
    {
        throw new BadMethodCallException('Stub embedding service has no provider.');
    }
}
