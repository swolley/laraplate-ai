<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Embeddings;

use NeuronAI\RAG\Document;

/**
 * A document whose formatted content is not text, which a provider has to read as no text.
 */
final class DocumentWithArrayFormattedContent extends Document
{
    /**
     * @var array<int, string>
     */
    public array $formattedContent = ['not text'];
}
