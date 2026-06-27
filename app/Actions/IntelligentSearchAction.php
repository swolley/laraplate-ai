<?php

declare(strict_types=1);

namespace Modules\AI\Actions;

use Illuminate\Support\Facades\Cache;
use Modules\Core\Search\Contracts\IQueryIntentParser;
use Modules\Core\Search\Contracts\ISearchPlanner;
use Modules\Core\Search\Contracts\ITextEmbedder;
use Modules\Core\Search\Services\EnsembleSearchService;

/**
 * Entry point for the AI-powered search pipeline.
 *
 * Orchestrates: plan generation -> intent parsing -> embedding ->
 * ensemble search -> evaluation -> retry loop -> caching -> pagination.
 */
final class IntelligentSearchAction
{
    private int $per_page = 20;

    private int $cache_ttl = 600;

    public function __construct(
        private readonly ISearchPlanner $planner,
        private readonly IQueryIntentParser $intent_parser,
        private readonly ?ITextEmbedder $embedder,
        private readonly EnsembleSearchService $ensemble,
    ) {}

    /**
     * Execute the full search pipeline.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int, search_meta: array<string, mixed>}}
     */
    public function execute(string $query, string $index, int $page = 1): array
    {
        $cache_key = $this->cacheKey($query, $index);
        $cached_results = $this->cachedResults($cache_key);

        if ($cached_results !== null) {
            return $this->buildPaginatedResponse($cached_results, $page, ['from_cache' => true]);
        }

        $plan = $this->planner->safePlan($query);
        $retry_policy = $this->planSection($plan, 'retry_policy');
        $max_attempts = $this->intValue($retry_policy['max_attempts'] ?? null, 2);
        $attempt = 0;
        $results = [];
        $search_meta = [];

        do {
            $attempt++;
            $pipeline_result = $this->runSearchPipeline($plan, $query, $index);
            $results = $pipeline_result['results'];
            $search_meta = $pipeline_result['meta'];
            $quality = $this->evaluateResults($results);

            if (! $this->shouldRetry($quality, $attempt, $plan)) {
                break;
            }

            $plan = $this->refinePlan($plan, $quality);
        } while ($attempt < $max_attempts);

        $search_meta['attempts'] = $attempt;
        $plan_meta = $this->planSection($plan, 'meta');
        $search_meta['plan_source'] = is_string($plan_meta['source'] ?? null)
            ? $plan_meta['source']
            : 'unknown';

        Cache::put($cache_key, $results, $this->cache_ttl);

        return $this->buildPaginatedResponse($results, $page, $search_meta);
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{results: list<array{id: string, score: float, source: array<string, mixed>}>, meta: array<string, mixed>}
     */
    private function runSearchPipeline(array $plan, string $query, string $index): array
    {
        $intent = $this->intent_parser->parse($query);
        $final_query = $intent['query']['expanded'];

        $vector = null;

        if ($this->shouldEmbed($plan)) {
            $vector = $this->embedder?->embed($final_query);
        }

        return $this->ensemble->search($intent, $vector, $final_query, $plan, $index);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function shouldEmbed(array $plan): bool
    {
        if (! $this->embedder instanceof ITextEmbedder) {
            return false;
        }

        if (! (bool) config('search.vector_search.enabled', false)) {
            return false;
        }

        $retrieval = $this->planSection($plan, 'retrieval');
        $vector = $this->planSection($plan, 'vector');

        return $this->boolValue($retrieval['use_vector'] ?? null, false)
            && $this->boolValue($vector['enabled'] ?? null, false);
    }

    /**
     * @param  list<array{id: string, score: float, source: array<string, mixed>}>  $results
     * @return array{avg_score: float, max_score: float, count: int, unique_ids: int}
     */
    private function evaluateResults(array $results): array
    {
        $scores = array_column($results, 'score');

        return [
            'avg_score' => $scores !== [] ? array_sum($scores) / count($scores) : 0.0,
            'max_score' => $scores !== [] ? max($scores) : 0.0,
            'count' => count($results),
            'unique_ids' => count(array_unique(array_column($results, 'id'))),
        ];
    }

    /**
     * @param  array{avg_score: float, max_score: float, count: int, unique_ids: int}  $quality
     * @param  array<string, mixed>  $plan
     */
    private function shouldRetry(array $quality, int $attempt, array $plan): bool
    {
        $retry_policy = $this->planSection($plan, 'retry_policy');

        if (! $this->boolValue($retry_policy['enabled'] ?? null, true)) {
            return false;
        }

        $max_attempts = $this->intValue($retry_policy['max_attempts'] ?? null, 2);
        $threshold = $this->floatValue($retry_policy['threshold_avg_score'] ?? null, 1.5);

        return $attempt < $max_attempts
            && ($quality['count'] < 5 || $quality['avg_score'] < $threshold || $quality['unique_ids'] < 3);
    }

    /**
     * Adjust plan weights to improve results on retry.
     *
     * @param  array<string, mixed>  $plan
     * @param  array{avg_score: float, max_score: float, count: int, unique_ids: int}  $quality
     * @return array<string, mixed>
     */
    private function refinePlan(array $plan, array $quality): array
    {
        if ($quality['count'] >= 5) {
            return $plan;
        }

        $retrieval = $this->planSection($plan, 'retrieval');
        $ensemble = $this->planSection($plan, 'ensemble');

        $retrieval['size'] = min(200, $this->intValue($retrieval['size'] ?? null, 50) + 20);

        $vector_w = $this->floatValue($ensemble['vector_weight'] ?? null, 0.35);
        $keyword_w = $this->floatValue($ensemble['keyword_weight'] ?? null, 0.35);
        $hybrid_w = $this->floatValue($ensemble['hybrid_weight'] ?? null, 0.30);

        $ensemble['vector_weight'] = min(0.55, $vector_w + 0.1);
        $ensemble['keyword_weight'] = max(0.2, $keyword_w - 0.05);
        $ensemble['hybrid_weight'] = max(0.2, $hybrid_w - 0.05);

        $plan['retrieval'] = $retrieval;
        $plan['ensemble'] = $ensemble;

        return $plan;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @param  array<string, mixed>  $search_meta
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int, search_meta: array<string, mixed>}}
     */
    private function buildPaginatedResponse(array $results, int $page, array $search_meta): array
    {
        $offset = ($page - 1) * $this->per_page;
        $data = array_slice($results, $offset, $this->per_page);

        return [
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $this->per_page,
                'total' => count($results),
                'search_meta' => $search_meta,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function cachedResults(string $cache_key): ?array
    {
        if (! Cache::has($cache_key)) {
            return null;
        }

        $cached = Cache::get($cache_key);

        if (! is_array($cached)) {
            return null;
        }

        $results = [];

        foreach ($cached as $item) {
            if (! is_array($item)) {
                return null;
            }

            $results[] = $item;
        }

        return $results;
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

    private function cacheKey(string $query, string $index): string
    {
        return "intelligent_search:{$index}:" . md5($query);
    }
}
