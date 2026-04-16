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
        private ISearchPlanner $planner,
        private IQueryIntentParser $intent_parser,
        private ?ITextEmbedder $embedder,
        private EnsembleSearchService $ensemble,
    ) {}

    /**
     * Execute the full search pipeline.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int, search_meta: array<string, mixed>}}
     */
    public function execute(string $query, string $index, int $page = 1): array
    {
        $cache_key = $this->cacheKey($query, $index);

        if (Cache::has($cache_key)) {
            return $this->paginate(Cache::get($cache_key), $page);
        }

        $plan = $this->planner->safePlan($query);
        $max_attempts = (int) ($plan['retry_policy']['max_attempts'] ?? 2);
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
        $search_meta['plan_source'] = $plan['meta']['source'] ?? 'unknown';

        Cache::put($cache_key, $results, $this->cache_ttl);

        $paginated = $this->paginate($results, $page);
        $paginated['meta']['search_meta'] = $search_meta;

        return $paginated;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array{results: list<array{id: string, score: float, source: array<string, mixed>}>, meta: array<string, mixed>}
     */
    private function runSearchPipeline(array $plan, string $query, string $index): array
    {
        $intent = $this->intent_parser->parse($query);
        $final_query = $intent['query']['expanded'] ?? $query;

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
        if ($this->embedder === null) {
            return false;
        }

        if (! (bool) config('search.vector_search.enabled', false)) {
            return false;
        }

        return (bool) ($plan['retrieval']['use_vector'] ?? false)
            && (bool) ($plan['vector']['enabled'] ?? false);
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
        if (! ($plan['retry_policy']['enabled'] ?? true)) {
            return false;
        }

        $max_attempts = (int) ($plan['retry_policy']['max_attempts'] ?? 2);
        $threshold = (float) ($plan['retry_policy']['threshold_avg_score'] ?? 1.5);

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
        if ($quality['count'] < 5) {
            $plan['retrieval']['size'] = min(200, (int) ($plan['retrieval']['size'] ?? 50) + 20);

            $vector_w = (float) ($plan['ensemble']['vector_weight'] ?? 0.35);
            $keyword_w = (float) ($plan['ensemble']['keyword_weight'] ?? 0.35);
            $hybrid_w = (float) ($plan['ensemble']['hybrid_weight'] ?? 0.30);

            $plan['ensemble']['vector_weight'] = min(0.55, $vector_w + 0.1);
            $plan['ensemble']['keyword_weight'] = max(0.2, $keyword_w - 0.05);
            $plan['ensemble']['hybrid_weight'] = max(0.2, $hybrid_w - 0.05);
        }

        return $plan;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int}}
     */
    private function paginate(array $results, int $page): array
    {
        return [
            'data' => collect($results)
                ->slice(($page - 1) * $this->per_page, $this->per_page)
                ->values()
                ->all(),

            'meta' => [
                'page' => $page,
                'per_page' => $this->per_page,
                'total' => count($results),
            ],
        ];
    }

    private function cacheKey(string $query, string $index): string
    {
        return "intelligent_search:{$index}:" . md5($query);
    }
}
