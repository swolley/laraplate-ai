<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Modules\AI\Ai\Agents\ChatAgent;
use NeuronAI\Chat\Messages\UserMessage;

use function ai_config_string;

/**
 * Search-specific LLM service wrapping NeuronAI via ChatAgent.
 *
 * Provides structured search plan generation and intent extraction
 * for the search orchestration pipeline.
 */
class LlmSearchService
{
    public function __construct(
        private readonly ?string $provider = null,
    ) {}

    /**
     * Generate a structured search plan from an LLM.
     *
     * @return array<string, mixed>
     */
    public function generateSearchPlan(string $query): array
    {
        $system_prompt = $this->getSearchPlanSystemPrompt();
        $agent = $this->createAgent($system_prompt);

        $response = $agent->chat(new UserMessage(
            "Generate a search plan for: {$query}",
        ))->getMessage();

        return $this->parseJsonResponse($response->getContent() ?? '');
    }

    /**
     * Extract search intent (keywords, filters, expanded query) from a user query.
     *
     * @return array{keywords: list<string>, filters: array<string, mixed>, query_expansion: array{must: string}}
     */
    public function extractSearchIntent(string $query): array
    {
        $system_prompt = $this->getIntentExtractionSystemPrompt();
        $agent = $this->createAgent($system_prompt);

        $response = $agent->chat(new UserMessage($query))->getMessage();
        $parsed = $this->parseJsonResponse($response->getContent() ?? '');

        return [
            'keywords' => $this->stringListValue($parsed, 'keywords'),
            'filters' => $this->arrayValue($parsed, 'filters'),
            'query_expansion' => [
                'must' => $this->expandedQueryValue($parsed, $query),
            ],
        ];
    }

    private function createAgent(string $system_prompt): ChatAgent
    {
        $provider_name = $this->provider ?? ai_config_string(
            'ai.features.search_orchestration.default_provider',
            ai_config_string('ai.features.chat.default_provider', 'ollama'),
        );

        return ChatAgent::make($provider_name, $system_prompt);
    }

    private function getSearchPlanSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a search orchestration system. Given a user query, produce a structured search plan as JSON.

You must decide:
- retrieval strategy (fulltext, vector, hybrid)
- ranking strategy (reranker yes/no, top_k)
- retry policy (max_attempts, threshold)
- filters (date_range if applicable)
- ensemble weights (keyword_weight, vector_weight, hybrid_weight)

Return ONLY valid JSON with this structure:
{
  "strategy": "hybrid",
  "retrieval": {"use_fulltext": true, "use_vector": true, "use_ensemble": true, "size": 50},
  "ensemble": {"enabled": true, "keyword_weight": 0.35, "vector_weight": 0.35, "hybrid_weight": 0.30, "agreement_boost": 0.15, "rrf_k": 60, "rrf_weight": 0.25},
  "ranking": {"use_reranker": true, "rerank_top_k": 30},
  "vector": {"enabled": true, "weight": 0.2},
  "filters": {"date_range": null},
  "retry_policy": {"enabled": true, "max_attempts": 2, "threshold_avg_score": 1.5}
}
PROMPT;
    }

    private function getIntentExtractionSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a search intent extraction system. Given a user query, extract structured information as JSON.

Return ONLY valid JSON with:
{
  "keywords": ["keyword1", "keyword2"],
  "filters": {"date_range": null},
  "query_expansion": {"must": "expanded query text"}
}

Rules:
- keywords: the most important search terms
- query_expansion.must: an expanded/reformulated version of the query for better search results
- filters.date_range: null unless the query explicitly mentions dates
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonResponse(string $content): array
    {
        $cleaned = $content;

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $cleaned, $matches)) {
            $cleaned = $matches[1];
        }

        $cleaned = mb_trim($cleaned);

        $decoded = json_decode($cleaned, true);

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return list<string>
     */
    private function stringListValue(array $parsed, string $key): array
    {
        $value = $parsed[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $items[] = $item;

                continue;
            }

            if (is_scalar($item)) {
                $items[] = (string) $item;
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function arrayValue(array $parsed, string $key): array
    {
        $value = $parsed[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function expandedQueryValue(array $parsed, string $fallback): string
    {
        $expansion = $parsed['query_expansion'] ?? null;

        if (! is_array($expansion)) {
            return $fallback;
        }

        $must = $expansion['must'] ?? null;

        if (is_string($must) && $must !== '') {
            return $must;
        }

        if (is_scalar($must)) {
            return (string) $must;
        }

        return $fallback;
    }
}
