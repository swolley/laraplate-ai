<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Core\Search\Contracts\ISearchPlanner;
use Throwable;

/**
 * AI-powered search orchestrator that generates execution plans via LLM.
 *
 * Falls back to rule-based heuristics when the LLM call fails
 * or returns an invalid plan.
 */
final readonly class SearchOrchestratorAgent implements ISearchPlanner
{
    public function __construct(
        private LlmSearchService $llm,
    ) {}

    /**
     * Generate a cached search plan via LLM with guardrails.
     *
     * @return array<string, mixed>
     */
    public function plan(string $query): array
    {
        $cache_key = $this->cacheKey($query);

        return Cache::remember($cache_key, 600, function () use ($query): array {
            $raw = $this->llm->generateSearchPlan($query);

            return $this->sanitizePlan($raw, $query);
        });
    }

    public function safePlan(string $query): array
    {
        try {
            $plan = $this->plan($query);

            if (! $this->isValidPlan($plan)) {
                return $this->fallbackPlan($query);
            }

            return $plan;
        } catch (Throwable) {
            return $this->fallbackPlan($query);
        }
    }

    public function fallbackPlan(string $query): array
    {
        $is_short = mb_strlen($query) < 20;
        $has_numbers = (bool) preg_match('/\d/', $query);
        $vector_globally_enabled = (bool) config('search.vector_search.enabled', false);
        $use_vector = $vector_globally_enabled && ! $has_numbers;

        $use_reranker = (bool) config('search.features.reranker', true);
        $rerank_top_k = $this->intValue(config('search.reranker.top_k'), 30);

        return [
            'strategy' => $use_vector ? 'hybrid' : 'fulltext',
            'retrieval' => [
                'use_fulltext' => true,
                'use_vector' => $use_vector,
                'use_ensemble' => true,
                'size' => $is_short ? 80 : 50,
            ],
            'ensemble' => [
                'enabled' => true,
                'keyword_weight' => $use_vector ? ($is_short ? 0.30 : 0.35) : 1.0,
                'vector_weight' => $use_vector ? ($is_short ? 0.40 : 0.35) : 0.0,
                'hybrid_weight' => $use_vector ? ($is_short ? 0.30 : 0.30) : 0.0,
                'agreement_boost' => 0.15,
                'rrf_k' => 60,
                'rrf_weight' => 0.25,
            ],
            'ranking' => [
                'use_reranker' => $use_reranker,
                'rerank_top_k' => $rerank_top_k,
            ],
            'vector' => [
                'enabled' => $use_vector,
                'weight' => $is_short ? 0.4 : 0.2,
            ],
            'filters' => [
                'date_range' => null,
            ],
            'retry_policy' => [
                'enabled' => true,
                'max_attempts' => 2,
                'threshold_avg_score' => 1.5,
            ],
            'meta' => [
                'source' => 'fallback_rules',
            ],
        ];
    }

    /**
     * Normalize and clamp raw LLM output into a safe search plan.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function sanitizePlan(array $plan, string $query): array
    {
        $vector_globally_enabled = (bool) config('search.vector_search.enabled', false);
        $retrieval = $this->planSection($plan, 'retrieval');
        $llm_wants_vector = $this->boolValue($retrieval['use_vector'] ?? null, true);
        $use_vector = $vector_globally_enabled && $llm_wants_vector;
        $ensemble = $this->planSection($plan, 'ensemble');
        $ranking = $this->planSection($plan, 'ranking');
        $vector = $this->planSection($plan, 'vector');
        $filters = $this->planSection($plan, 'filters');
        $retry_policy = $this->planSection($plan, 'retry_policy');
        $strategy = $plan['strategy'] ?? 'hybrid';

        return [
            'strategy' => $use_vector
                ? $this->sanitizeStrategy(is_string($strategy) ? $strategy : 'hybrid')
                : 'fulltext',

            'retrieval' => [
                'use_fulltext' => $this->boolValue($retrieval['use_fulltext'] ?? null, true),
                'use_vector' => $use_vector,
                'use_ensemble' => $this->boolValue($retrieval['use_ensemble'] ?? null, true),
                'size' => $this->clamp($this->intValue($retrieval['size'] ?? null, 50), 10, 200),
            ],

            'ensemble' => [
                'enabled' => $this->boolValue($ensemble['enabled'] ?? null, true),
                'keyword_weight' => $this->clampFloat($this->floatValue($ensemble['keyword_weight'] ?? null, 0.35), 0.0, 1.0),
                'vector_weight' => $use_vector ? $this->clampFloat($this->floatValue($ensemble['vector_weight'] ?? null, 0.35), 0.0, 1.0) : 0.0,
                'hybrid_weight' => $use_vector ? $this->clampFloat($this->floatValue($ensemble['hybrid_weight'] ?? null, 0.30), 0.0, 1.0) : 0.0,
                'agreement_boost' => $this->clampFloat($this->floatValue($ensemble['agreement_boost'] ?? null, 0.15), 0.0, 1.0),
                'rrf_k' => $this->clamp($this->intValue($ensemble['rrf_k'] ?? null, 60), 10, 200),
                'rrf_weight' => $this->clampFloat($this->floatValue($ensemble['rrf_weight'] ?? null, 0.25), 0.0, 1.0),
            ],

            'ranking' => [
                'use_reranker' => $this->boolValue($ranking['use_reranker'] ?? null, true),
                'rerank_top_k' => $this->clamp($this->intValue($ranking['rerank_top_k'] ?? null, 30), 5, 100),
            ],

            'vector' => [
                'enabled' => $use_vector,
                'weight' => $this->clampFloat($this->floatValue($vector['weight'] ?? null, 0.2), 0.0, 1.0),
            ],

            'filters' => [
                'date_range' => $filters['date_range'] ?? null,
            ],

            'retry_policy' => [
                'enabled' => $this->boolValue($retry_policy['enabled'] ?? null, true),
                'max_attempts' => $this->clamp($this->intValue($retry_policy['max_attempts'] ?? null, 2), 1, 3),
                'threshold_avg_score' => $this->clampFloat($this->floatValue($retry_policy['threshold_avg_score'] ?? null, 1.5), 0.1, 10.0),
            ],

            'meta' => [
                'query' => $query,
                'source' => 'llm+guardrails',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function isValidPlan(array $plan): bool
    {
        return isset($plan['retrieval'])
            && isset($plan['ranking'])
            && isset($plan['vector'])
            && isset($plan['ensemble']);
    }

    private function sanitizeStrategy(string $strategy): string
    {
        if (in_array($strategy, ['hybrid', 'fulltext', 'vector'], true)) {
            return $strategy;
        }

        return 'hybrid';
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function planSection(array $plan, string $key): array
    {
        $section = $plan[$key] ?? [];

        return is_array($section) ? $section : [];
    }

    private function intValue(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function floatValue(mixed $value, float $default): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    private function boolValue(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return $default;
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    private function clampFloat(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    private function cacheKey(string $query): string
    {
        return 'search_orchestrator:' . md5($query);
    }
}
