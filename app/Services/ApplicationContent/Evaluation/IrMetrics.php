<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Evaluation;

/**
 * Shared information-retrieval math for `@k` evaluation metrics.
 *
 * Computes per-case, pre-division contributions (one relevant evaluation
 * case) so callers can sum contributions across cases and divide by their
 * own denominator (e.g. the count of relevant cases).
 */
final class IrMetrics
{
    /**
     * @param  list<string>  $hitIds  Ranked ids returned by retrieval, best match first.
     * @param  list<string>  $expectedIds  Ids considered relevant for this case.
     * @param  list<int>  $cutoffs  The `@k` cutoffs to compute contributions for.
     * @return array{precision: array<int, float>, recall: array<int, float>, ndcg: array<int, float>}
     */
    public static function atK(array $hitIds, array $expectedIds, array $cutoffs): array
    {
        $precision = [];
        $recall = [];
        $ndcg = [];

        foreach ($cutoffs as $k) {
            $top = array_slice($hitIds, 0, $k);
            $relevant_in_top = 0;
            $dcg = 0.0;

            foreach ($top as $rank => $id) {
                if (in_array($id, $expectedIds, true)) {
                    $relevant_in_top++;
                    $dcg += 1.0 / log($rank + 2, 2);
                }
            }

            $ideal = min(count($expectedIds), $k);
            $idcg = 0.0;

            for ($i = 0; $i < $ideal; $i++) {
                $idcg += 1.0 / log($i + 2, 2);
            }

            $precision[$k] = $k > 0 ? (float) ($relevant_in_top / $k) : 0.0;
            $recall[$k] = $expectedIds === [] ? 0.0 : (float) ($relevant_in_top / count($expectedIds));
            $ndcg[$k] = $idcg > 0.0 ? $dcg / $idcg : 0.0;
        }

        return ['precision' => $precision, 'recall' => $recall, 'ndcg' => $ndcg];
    }
}
