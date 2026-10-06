<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Documentation;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\SplitterInterface;
use Override;

/**
 * Splits a document into 150 chunks when its source name holds "large" and into 3 otherwise, so that one
 * source is bigger than an indexing batch.
 */
final class ManyChunksSplitter implements SplitterInterface
{
    /**
     * @return list<Document>
     */
    #[Override]
    public function splitDocument(Document $document): array
    {
        $count = str_contains($document->sourceName, 'large') ? 150 : 3;
        $chunks = [];

        for ($i = 0; $i < $count; $i++) {
            $chunk = new Document($document->getContent() . ' #' . $i);
            $chunk->sourceType = $document->sourceType;
            $chunk->sourceName = $document->sourceName;
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * @param  Document[]  $documents
     * @return list<Document>
     */
    #[Override]
    public function splitDocuments(array $documents): array
    {
        return array_merge(...array_map($this->splitDocument(...), $documents));
    }
}
