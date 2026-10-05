<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use RuntimeException;

/**
 * An embedding model produced no vector, or one whose length is not the dimensions its profile declares.
 */
final class EmbeddingDimensionMismatch extends RuntimeException
{
    public static function noEmbedding(EmbeddingModelProfile $profile): self
    {
        return new self("The embedding service returned no embedding for profile \"{$profile->key}\".");
    }

    public static function differs(EmbeddingModelProfile $profile, int $measured): self
    {
        return new self("Profile \"{$profile->key}\" declares {$profile->dimensions} dimensions but the model returned {$measured} dimensions.");
    }
}
