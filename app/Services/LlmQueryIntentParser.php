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
            'keywords' => $intent['keywords'],
            'date_range' => $this->parseDateRange($intent['filters']['date_range'] ?? null),
            'query' => [
                'expanded' => $intent['query_expansion']['must'],
            ],
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function parseDateRange(mixed $date_range): ?array
    {
        if (! is_array($date_range)) {
            return null;
        }

        $parsed = [];

        foreach ($date_range as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            $parsed[$key] = (string) $value;
        }

        return $parsed === [] ? null : $parsed;
    }
}
