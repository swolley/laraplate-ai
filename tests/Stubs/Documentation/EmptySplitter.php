<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Documentation;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\SplitterInterface;
use Override;

/**
 * A splitter that finds nothing to keep in any document.
 */
final class EmptySplitter implements SplitterInterface
{
    /**
     * @return list<Document>
     */
    #[Override]
    public function splitDocument(Document $document): array
    {
        return [];
    }

    /**
     * @param  Document[]  $documents
     * @return list<Document>
     */
    #[Override]
    public function splitDocuments(array $documents): array
    {
        return [];
    }
}
