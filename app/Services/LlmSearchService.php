<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Modules\AI\Ai\Agents\ChatAgent;
use NeuronAI\Chat\Messages\UserMessage;

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

        $content = $response->getContent();

        return $this->parseJsonResponse($content);
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

        $content = $response->getContent();

        $parsed = $this->parseJsonResponse($content);

        return [
            'keywords' => $parsed['keywords'] ?? [],
            'filters' => $parsed['filters'] ?? [],
            'query_expansion' => [
                'must' => $parsed['query_expansion']['must'] ?? $query,
            ],
        ];
    }

    private function createAgent(string $system_prompt): ChatAgent
    {
        $provider_name = $this->provider
            ?? (string) config(
                'ai.features.search_orchestration.default_provider',
                config('ai.features.chat.default_provider', 'ollama'),
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

        return $decoded;
    }
}
