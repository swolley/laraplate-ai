<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Modules\Core\Search\Contracts\ITextEmbedder;

/**
 * Bridge between the search pipeline and the AI module's EmbeddingService.
 *
 * Adapts the existing EmbeddingService to the ITextEmbedder contract
 * expected by the Core search infrastructure.
 */
final readonly class SearchEmbedder implements ITextEmbedder
{
    public function __construct(
        private EmbeddingService $embeddingService,
    ) {}

    public function embed(string $text): array
    {
        return $this->embeddingService->embedText($text);
    }
}
