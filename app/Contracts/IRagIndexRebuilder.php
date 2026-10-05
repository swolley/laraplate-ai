<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;

/**
 * Rebuilds the documentation (RAG) indexes for an embedding profile: the index mapping sized for
 * its vectors, and every document embedded again with it.
 */
interface IRagIndexRebuilder
{
    public function rebuild(EmbeddingModelProfile $target): void;
}
