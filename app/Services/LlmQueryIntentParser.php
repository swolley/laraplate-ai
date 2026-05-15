<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Modules\Core\Search\Contracts\IQueryIntentParser;

/**
 * LLM-powered query intent parser.
 *
 * Delegates to LlmSearchService for structured extraction of keywords,
 * date filters, and expanded query from raw user input.
 */
final readonly class LlmQueryIntentParser implements IQueryIntentParser
{
    public function __construct(
        private LlmSearchService $llm,
    ) {}

    public function parse(string $query): array
    {
        $intent = $this->llm->extractSearchIntent($query);

        return [
            'keywords' => $intent['keywords'] ?? [],
            'date_range' => $intent['filters']['date_range'] ?? null,
            'query' => [
                'expanded' => $intent['query_expansion']['must'] ?? $query,
            ],
        ];
    }
}
