<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Evaluation;

use Closure;
use InvalidArgumentException;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use Modules\Core\Search\DTOs\AdvancedSearchResult;

/**
 * Evaluates retrieval ranking quality per strategy (keyword, vector, hybrid,
 * fused, reranked) against a dataset of ground-truth cases.
 *
 * Reuses {@see IrMetrics::atK()} for the precision/recall/nDCG math and the
 * per-case denominator convention from {@see ApplicationContentEvaluationService}
 * (sum contributions across cases, divide by the count of cases that carry
 * ground truth).
 */
final readonly class ApplicationContentRetrievalStrategyEvaluationService
{
    /**
     * @var list<int>
     */
    private const array CUTOFFS = [1, 3, 5];

    /**
     * Strategies backed by {@see AdvancedSearchResult::$meta}'s `per_strategy` map.
     * Only strategies that actually ran for at least one scored case are reported.
     *
     * @var list<string>
     */
    private const array RANKED_STRATEGIES = ['keyword', 'vector', 'hybrid'];

    /**
     * Reporting order: ranked strategies first, then the two final orderings
     * (fused is always the pre-rerank result of `ids()`, reranked the post-rerank one).
     *
     * @var list<string>
     */
    private const array STRATEGIES = ['keyword', 'vector', 'hybrid', 'fused', 'reranked'];

    private Closure $clock;

    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    /**
     * @param  callable(ApplicationContentEvaluationCase, bool): AdvancedSearchResult  $retrieval  Invoked once with
     *                                                                                             `useReranker = false` (to read the per-strategy breakdown and the fused ordering) and once with
     *                                                                                             `useReranker = true` (to read the reranked ordering), for every case whose `expectedHitIds` is
     *                                                                                             non-empty. Cases without expected hit ids carry no ground truth for ranking quality and are
     *                                                                                             skipped entirely: no retrieval call, no latency sample, no metric contribution.
     * @return array<string, mixed>
     */
    public function evaluate(
        ApplicationContentEvaluationDataset $dataset,
        string $source,
        string $driver,
        callable $retrieval,
    ): array {
        $source = ApplicationContentSourceDescriptor::normalizeSource($source);

        if ($dataset->source !== $source || mb_trim($driver) === '' || mb_strlen($driver) > 100) {
            throw new InvalidArgumentException('Evaluation driver is invalid.');
        }

        $this->assertDatasetSource($dataset, $source);

        $records = [];

        foreach ($dataset->cases as $case) {
            if ($case->expectedHitIds === []) {
                continue;
            }

            $started_at = ($this->clock)();
            $off = $retrieval($case, false);
            $on = $retrieval($case, true);
            $elapsed = max(0.0, (($this->clock)() - $started_at) * 1000);

            $records[] = [
                'case' => $case,
                'orderings' => array_map(
                    fn (array $ids): array => $this->namespaceIds($ids, $source),
                    [
                        'keyword' => $this->rankedStrategyIds($off, 'keyword'),
                        'vector' => $this->rankedStrategyIds($off, 'vector'),
                        'hybrid' => $this->rankedStrategyIds($off, 'hybrid'),
                        'fused' => $off->ids(),
                        'reranked' => $on->ids(),
                    ],
                ),
                'executed' => [
                    'keyword' => $this->strategyPresent($off, 'keyword'),
                    'vector' => $this->strategyPresent($off, 'vector'),
                    'hybrid' => $this->strategyPresent($off, 'hybrid'),
                ],
                'latency_ms' => $elapsed,
            ];
        }

        return [
            'schema_version' => '1',
            'source' => $source,
            'driver' => mb_trim($driver),
            'dataset_version' => $dataset->version,
            'provider_version' => $dataset->providerVersion,
            'corpus_revision' => $dataset->corpusRevision,
            'data_classification' => $dataset->dataClassification,
            'case_count' => count($records),
            'pre_authorization' => true,
            'metrics' => $this->metrics($records),
            'latency_ms' => $this->latency($records),
            'slices' => $this->slices($records),
        ];
    }

    private function assertDatasetSource(ApplicationContentEvaluationDataset $dataset, string $source): void
    {
        foreach ($dataset->cases as $case) {
            foreach ($case->expectedHitIds as $id) {
                if (! str_starts_with($id, $source . ':')) {
                    throw new InvalidArgumentException('Evaluation case source does not match the requested source.');
                }
            }
        }
    }

    /**
     * Namespaces bare engine ids (`EnsembleSearchService` emits `(string) $model->getKey()`,
     * e.g. `"2"`) into the same `"{source}:{key}"` format as `expectedHitIds`, so the two
     * are comparable. Applied exactly once, centrally, right where the five orderings are
     * extracted, so the strategy-id lists downstream are always already namespaced.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function namespaceIds(array $ids, string $source): array
    {
        return array_map(static fn (string $id): string => "{$source}:{$id}", $ids);
    }

    /**
     * Rank-ordered ids for one keyword/vector/hybrid strategy, read from the
     * ensemble's per-strategy map. The map is insertion-ordered by rank, but
     * this re-sorts by the explicit `rank` field to not depend on that.
     *
     * @return list<string>
     */
    private function rankedStrategyIds(AdvancedSearchResult $result, string $name): array
    {
        $hits = $result->meta['per_strategy'][$name] ?? null;

        if (! is_array($hits)) {
            return [];
        }

        $ranked = array_values($hits);
        usort($ranked, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return array_map(static fn (array $hit): string => $hit['id'], $ranked);
    }

    /**
     * Whether a keyword/vector/hybrid strategy actually executed for this
     * result, i.e. it has a key in `meta['per_strategy']` (even if it
     * returned zero hits). Used to decide whether to report the strategy
     * at all: a strategy never executed for any scored case is omitted
     * from the report instead of being padded with zero metrics.
     */
    private function strategyPresent(AdvancedSearchResult $result, string $name): bool
    {
        $per_strategy = $result->meta['per_strategy'] ?? null;

        return is_array($per_strategy) && array_key_exists($name, $per_strategy);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, array<string, float>>
     */
    private function metrics(array $records): array
    {
        $metrics = [];

        foreach (self::STRATEGIES as $name) {
            if (in_array($name, self::RANKED_STRATEGIES, true) && ! $this->strategyExecuted($records, $name)) {
                continue;
            }

            $metrics[$name] = $this->strategyMetrics($records, $name);
        }

        return $metrics;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function strategyExecuted(array $records, string $name): bool
    {
        foreach ($records as $record) {
            if ($record['executed'][$name]) {
                return true;
            }
        }

        return false;
    }

    /**
     * The denominator for a strategy's averaged metrics: for `fused`/`reranked`
     * (always present, sourced straight from `AdvancedSearchResult::ids()`) this
     * is every scored case; for keyword/vector/hybrid it is only the scored
     * cases where the strategy actually executed (had a key in
     * `meta['per_strategy']`), so a strategy that ran on a subset of cases
     * (e.g. vector skipped for cases without an embedding) is not diluted by
     * the cases where it did not run.
     *
     * @param  list<array<string, mixed>>  $records
     */
    private function strategyCaseCount(array $records, string $name): int
    {
        if (! in_array($name, self::RANKED_STRATEGIES, true)) {
            return count($records);
        }

        $count = 0;

        foreach ($records as $record) {
            if ($record['executed'][$name]) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, float>
     */
    private function strategyMetrics(array $records, string $name): array
    {
        $relevant_cases = $this->strategyCaseCount($records, $name);
        $hits_at_k = 0;
        $reciprocal_rank = 0.0;
        $precision_sum = array_fill_keys(self::CUTOFFS, 0.0);
        $recall_sum = array_fill_keys(self::CUTOFFS, 0.0);
        $ndcg_sum = array_fill_keys(self::CUTOFFS, 0.0);

        foreach ($records as $record) {
            if (in_array($name, self::RANKED_STRATEGIES, true) && ! $record['executed'][$name]) {
                continue;
            }

            /** @var ApplicationContentEvaluationCase $case */
            $case = $record['case'];
            $ids = $record['orderings'][$name];
            $expected = $case->expectedHitIds;
            $first_rank = null;

            foreach ($ids as $index => $id) {
                if (in_array($id, $expected, true)) {
                    $first_rank ??= $index + 1;
                }
            }

            if ($first_rank !== null) {
                $hits_at_k++;
                $reciprocal_rank += 1 / $first_rank;
            }

            $contributions = IrMetrics::atK($ids, $expected, self::CUTOFFS);

            foreach (self::CUTOFFS as $k) {
                $precision_sum[$k] += $contributions['precision'][$k];
                $recall_sum[$k] += $contributions['recall'][$k];
                $ndcg_sum[$k] += $contributions['ndcg'][$k];
            }
        }

        $precision = [];
        $recall = [];
        $ndcg = [];

        foreach (self::CUTOFFS as $k) {
            $precision["precision_at_{$k}"] = $this->ratio($precision_sum[$k], $relevant_cases);
            $recall["recall_at_{$k}"] = $this->ratio($recall_sum[$k], $relevant_cases);
            $ndcg["ndcg_at_{$k}"] = $this->ratio($ndcg_sum[$k], $relevant_cases);
        }

        return [
            'hit_at_5' => $this->ratio($hits_at_k, $relevant_cases),
            'mean_reciprocal_rank' => $this->ratio($reciprocal_rank, $relevant_cases),
            ...$precision,
            ...$recall,
            ...$ndcg,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, float>
     */
    private function latency(array $records): array
    {
        if ($records === []) {
            return ['average' => 0.0, 'p50' => 0.0, 'p95' => 0.0, 'max' => 0.0];
        }

        $values = array_map(static fn (array $record): float => $record['latency_ms'], $records);
        sort($values, SORT_NUMERIC);

        return [
            'average' => $this->rounded(array_sum($values) / count($values)),
            'p50' => $this->rounded($this->percentile($values, 0.50)),
            'p95' => $this->rounded($this->percentile($values, 0.95)),
            'max' => $this->rounded(max($values)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, array<string, array<string, array<string, float>>>>
     */
    private function slices(array $records): array
    {
        $locales = [];
        $categories = [];

        foreach ($records as $record) {
            /** @var ApplicationContentEvaluationCase $case */
            $case = $record['case'];
            $locales[$case->locale][] = $record;

            foreach ($case->slices as $slice) {
                $categories[$slice][] = $record;
            }
        }

        ksort($locales, SORT_STRING);
        ksort($categories, SORT_STRING);

        return [
            'locale' => array_map(fn (array $slice): array => $this->metrics($slice), $locales),
            'category' => array_map(fn (array $slice): array => $this->metrics($slice), $categories),
        ];
    }

    private function ratio(float|int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : $this->rounded($numerator / $denominator);
    }

    /**
     * @param  list<float>  $values
     */
    private function percentile(array $values, float $percentile): float
    {
        $index = max(0, (int) ceil($percentile * count($values)) - 1);

        return $values[$index];
    }

    private function rounded(float $value): float
    {
        return round($value, 4);
    }
}
