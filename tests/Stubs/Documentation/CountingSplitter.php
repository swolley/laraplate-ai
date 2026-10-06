<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Documentation;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\SplitterInterface;
use Override;

/**
 * Leaves each document whole and counts how often it was asked to split one.
 */
final class CountingSplitter implements SplitterInterface
{
    public int $calls = 0;

    /**
     * @return list<Document>
     */
    #[Override]
    public function splitDocument(Document $document): array
    {
        $this->calls++;

        return [$document];
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
