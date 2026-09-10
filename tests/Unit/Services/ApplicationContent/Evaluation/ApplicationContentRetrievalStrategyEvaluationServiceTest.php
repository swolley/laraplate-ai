<?php

declare(strict_types=1);

use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationCase;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationDataset;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentRetrievalStrategyEvaluationService;
use Modules\Core\ApplicationContent\Data\ApplicationContentAuthorization;
use Modules\Core\Search\DTOs\AdvancedSearchResult;

function retrievalStrategyEvaluationCase(string $id, array $expectedIds): ApplicationContentEvaluationCase
{
    return new ApplicationContentEvaluationCase(
        id: $id,
        query: "query {$id}",
        locale: 'en',
        limit: 5,
        expectedHitIds: $expectedIds,
        expectedCitationReferences: [],
        expectAuthorizedEmpty: false,
        expectSupportedAnswer: false,
        expectAbstention: false,
        slices: [],
        authorization: new ApplicationContentAuthorization('evaluation.contents.select', null),
    );
}

/**
 * @param  list<string>  $orderedIds
 * @return array<string, array{id: string, score: float, raw_score: float, score_details: array<never>, source: array<never>, rank: int}>
 */
function retrievalStrategyRanking(array $orderedIds): array
{
    $out = [];
    $rank = 1;

    foreach ($orderedIds as $id) {
        $out[$id] = [
            'id' => $id,
            'score' => 1.0,
            'raw_score' => 1.0,
            'score_details' => [],
            'source' => [],
            'rank' => $rank,
        ];
        $rank++;
    }

    return $out;
}

/**
 * @param  list<string>  $finalIds
 * @param  array<string, array<string, array{id: string, score: float, raw_score: float, score_details: array<never>, source: array<never>, rank: int}>>  $perStrategy
 */
function retrievalStrategyResult(array $finalIds, array $perStrategy = []): AdvancedSearchResult
{
    $hits = array_map(static fn (string $id): array => [
        'id' => $id,
        'score' => 1.0,
        'raw_score' => 1.0,
        'score_details' => [],
        'source' => [],
    ], $finalIds);

    return new AdvancedSearchResult(
        hits: $hits,
        total: count($hits),
        page: 1,
        perPage: max(1, count($hits)),
        totalPages: 1,
        meta: ['per_strategy' => $perStrategy],
    );
}

it('scores keyword, vector, hybrid, fused and reranked orderings against the ground truth', function (): void {
    $dataset = new ApplicationContentEvaluationDataset(
        version: '1',
        providerVersion: 'p',
        corpusRevision: 'c',
        cases: [
            retrievalStrategyEvaluationCase('case-1', ['cms.contents:2']),
        ],
    );

    // Engine ids are bare (`EnsembleSearchService` emits `(string) $model->getKey()`), not
    // pre-namespaced with the source. The service is responsible for prefixing them to
    // "cms.contents:{id}" before comparing against `expectedHitIds`.
    $off = retrievalStrategyResult(
        finalIds: ['2', '1', '3'],
        perStrategy: [
            'keyword' => retrievalStrategyRanking(['1', '2', '3']),
            'vector' => retrievalStrategyRanking(['2', '1', '3']),
            'hybrid' => retrievalStrategyRanking(['2', '3', '1']),
        ],
    );
    $on = retrievalStrategyResult(finalIds: ['2', '1', '3']);

    $ticks = [0.000, 0.010];
    $service = new ApplicationContentRetrievalStrategyEvaluationService(
        clock: static function () use (&$ticks): float {
            return array_shift($ticks);
        },
    );

    $report = $service->evaluate(
        $dataset,
        'cms.contents',
        'elasticsearch',
        static fn (ApplicationContentEvaluationCase $case, bool $useReranker): AdvancedSearchResult => $useReranker ? $on : $off,
    );

    expect($report['schema_version'])->toBe('1')
        ->and($report['source'])->toBe('cms.contents')
        ->and($report['driver'])->toBe('elasticsearch')
        ->and($report['dataset_version'])->toBe('1')
        ->and($report['provider_version'])->toBe('p')
        ->and($report['corpus_revision'])->toBe('c')
        ->and($report['pre_authorization'])->toBeTrue()
        ->and($report['case_count'])->toBe(1)
        ->and($report['latency_ms'])->toBe([
            'average' => 10.0,
            'p50' => 10.0,
            'p95' => 10.0,
            'max' => 10.0,
        ])
        ->and($report)->not->toHaveKeys(['queries', 'prompts', 'authorizations']);

    // keyword ranks the relevant id (cms.contents:2) at rank 2 of 3.
    expect($report['metrics']['keyword'])->toMatchArray([
        'precision_at_1' => 0.0, 'precision_at_3' => 0.3333, 'precision_at_5' => 0.2,
        'recall_at_1' => 0.0, 'recall_at_3' => 1.0, 'recall_at_5' => 1.0,
        'ndcg_at_1' => 0.0, 'ndcg_at_3' => 0.6309, 'ndcg_at_5' => 0.6309,
        'hit_at_5' => 1.0, 'mean_reciprocal_rank' => 0.5,
    ]);

    // vector and hybrid both rank the relevant id first.
    expect($report['metrics']['vector'])->toMatchArray([
        'precision_at_1' => 1.0, 'precision_at_3' => 0.3333, 'precision_at_5' => 0.2,
        'recall_at_1' => 1.0, 'recall_at_3' => 1.0, 'recall_at_5' => 1.0,
        'ndcg_at_1' => 1.0, 'ndcg_at_3' => 1.0, 'ndcg_at_5' => 1.0,
        'hit_at_5' => 1.0, 'mean_reciprocal_rank' => 1.0,
    ]);
    expect($report['metrics']['hybrid'])->toMatchArray([
        'precision_at_1' => 1.0, 'recall_at_3' => 1.0, 'ndcg_at_1' => 1.0,
        'hit_at_5' => 1.0, 'mean_reciprocal_rank' => 1.0,
    ]);

    // fused (off->ids()) and reranked (on->ids()) both rank the relevant id first.
    expect($report['metrics']['fused'])->toMatchArray([
        'precision_at_1' => 1.0, 'recall_at_3' => 1.0, 'mean_reciprocal_rank' => 1.0,
    ]);
    expect($report['metrics']['reranked'])->toMatchArray([
        'precision_at_1' => 1.0, 'recall_at_1' => 1.0,
        'ndcg_at_1' => 1.0, 'ndcg_at_3' => 1.0, 'ndcg_at_5' => 1.0,
        'mean_reciprocal_rank' => 1.0,
    ]);

    // Slices mirror Phase 1's nesting: per-locale and per-category breakdowns of the same per-strategy metrics.
    expect($report['slices']['locale']['en']['fused']['precision_at_1'])->toBe(1.0)
        ->and($report['slices']['category'])->toBe([]);
});

it('skips retrieval and scoring for cases without expected hit ids', function (): void {
    $dataset = new ApplicationContentEvaluationDataset(
        version: '1',
        providerVersion: 'p',
        corpusRevision: 'c',
        cases: [
            retrievalStrategyEvaluationCase('scored', ['cms.contents:2']),
            retrievalStrategyEvaluationCase('unscored', []),
        ],
    );

    $off = retrievalStrategyResult(
        finalIds: ['2', '1'],
        perStrategy: [
            'keyword' => retrievalStrategyRanking(['2', '1']),
            'vector' => retrievalStrategyRanking(['2', '1']),
            'hybrid' => retrievalStrategyRanking(['2', '1']),
        ],
    );
    $on = retrievalStrategyResult(finalIds: ['2', '1']);
    $calls = [];
    $service = new ApplicationContentRetrievalStrategyEvaluationService;

    $report = $service->evaluate(
        $dataset,
        'cms.contents',
        'elasticsearch',
        static function (ApplicationContentEvaluationCase $case, bool $useReranker) use ($off, $on, &$calls): AdvancedSearchResult {
            $calls[] = [$case->id, $useReranker];

            return $useReranker ? $on : $off;
        },
    );

    expect($calls)->toBe([['scored', false], ['scored', true]])
        ->and($report['case_count'])->toBe(1)
        ->and($report['metrics']['fused']['precision_at_1'])->toBe(1.0);
});

it('omits a keyword/vector/hybrid strategy from the report when it never appears in the per-strategy map', function (): void {
    $dataset = new ApplicationContentEvaluationDataset(
        version: '1',
        providerVersion: 'p',
        corpusRevision: 'c',
        cases: [
            retrievalStrategyEvaluationCase('keyword-only', ['cms.contents:2']),
        ],
    );

    // Vector never runs for this dataset (e.g. no embedding available); hybrid likewise.
    $off = retrievalStrategyResult(
        finalIds: ['2', '1'],
        perStrategy: [
            'keyword' => retrievalStrategyRanking(['2', '1']),
        ],
    );
    $on = retrievalStrategyResult(finalIds: ['2', '1']);
    $service = new ApplicationContentRetrievalStrategyEvaluationService;

    $report = $service->evaluate(
        $dataset,
        'cms.contents',
        'elasticsearch',
        static fn (ApplicationContentEvaluationCase $case, bool $useReranker): AdvancedSearchResult => $useReranker ? $on : $off,
    );

    expect($report['metrics'])->toHaveKeys(['keyword', 'fused', 'reranked'])
        ->and($report['metrics'])->not->toHaveKey('vector')
        ->and($report['metrics'])->not->toHaveKey('hybrid');
});

it('averages a partially-present strategy over only the cases where it actually ran, not diluted by the absent ones', function (): void {
    $dataset = new ApplicationContentEvaluationDataset(
        version: '1',
        providerVersion: 'p',
        corpusRevision: 'c',
        cases: [
            retrievalStrategyEvaluationCase('vector-ran', ['cms.contents:2']),
            retrievalStrategyEvaluationCase('vector-absent', ['cms.contents:2']),
        ],
    );

    // Vector runs (and is correct) for the first case only, e.g. because the second case's
    // content has no embedding. Keyword runs for both cases so it stays the control group.
    $withVector = retrievalStrategyResult(
        finalIds: ['2', '1'],
        perStrategy: [
            'keyword' => retrievalStrategyRanking(['2', '1']),
            'vector' => retrievalStrategyRanking(['2', '1']),
        ],
    );
    $withoutVector = retrievalStrategyResult(
        finalIds: ['2', '1'],
        perStrategy: [
            'keyword' => retrievalStrategyRanking(['2', '1']),
        ],
    );
    $on = retrievalStrategyResult(finalIds: ['2', '1']);
    $service = new ApplicationContentRetrievalStrategyEvaluationService;

    $report = $service->evaluate(
        $dataset,
        'cms.contents',
        'elasticsearch',
        static fn (ApplicationContentEvaluationCase $case, bool $useReranker): AdvancedSearchResult => match (true) {
            $useReranker => $on,
            $case->id === 'vector-ran' => $withVector,
            default => $withoutVector,
        },
    );

    expect($report['case_count'])->toBe(2)
        // vector ran (and hit) on 1 of the 2 scored cases: its average must reflect
        // only that case (precision_at_1 = 1.0), not be diluted to 0.5 by the absent case.
        ->and($report['metrics']['vector'])->toMatchArray([
            'precision_at_1' => 1.0,
            'recall_at_1' => 1.0,
            'hit_at_5' => 1.0,
            'mean_reciprocal_rank' => 1.0,
        ])
        // keyword ran (and hit) on both scored cases: unaffected control group.
        ->and($report['metrics']['keyword'])->toMatchArray([
            'precision_at_1' => 1.0,
            'recall_at_1' => 1.0,
            'hit_at_5' => 1.0,
            'mean_reciprocal_rank' => 1.0,
        ]);
});

it('produces a zero-safe latency report when every case is skipped for having no expected hit ids', function (): void {
    $dataset = new ApplicationContentEvaluationDataset(
        version: '1',
        providerVersion: 'p',
        corpusRevision: 'c',
        cases: [
            retrievalStrategyEvaluationCase('unscored-1', []),
            retrievalStrategyEvaluationCase('unscored-2', []),
        ],
    );
    $service = new ApplicationContentRetrievalStrategyEvaluationService;

    $report = $service->evaluate(
        $dataset,
        'cms.contents',
        'elasticsearch',
        static function (): never {
            throw new RuntimeException('retrieval must not be called for skipped cases');
        },
    );

    expect($report['case_count'])->toBe(0)
        ->and($report['latency_ms'])->toBe([
            'average' => 0.0,
            'p50' => 0.0,
            'p95' => 0.0,
            'max' => 0.0,
        ])
        // No case ever scored, so keyword/vector/hybrid never executed and are omitted;
        // fused/reranked stay present with zero-safe (not NaN/division-by-zero) metrics.
        ->and($report['metrics'])->toHaveKeys(['fused', 'reranked'])
        ->and($report['metrics'])->not->toHaveKey('keyword')
        ->and($report['metrics'])->not->toHaveKey('vector')
        ->and($report['metrics'])->not->toHaveKey('hybrid')
        ->and($report['metrics']['fused'])->toMatchArray([
            'precision_at_1' => 0.0, 'recall_at_1' => 0.0, 'ndcg_at_1' => 0.0,
            'hit_at_5' => 0.0, 'mean_reciprocal_rank' => 0.0,
        ]);
});

it('rejects a driver or dataset source that does not match the requested source before calling retrieval', function (): void {
    $dataset = new ApplicationContentEvaluationDataset(
        version: '1',
        providerVersion: 'p',
        corpusRevision: 'c',
        cases: [
            retrievalStrategyEvaluationCase('case-1', ['cms.contents:2']),
        ],
    );
    $calls = 0;

    expect(fn () => (new ApplicationContentRetrievalStrategyEvaluationService)->evaluate(
        $dataset,
        'erp.orders',
        'elasticsearch',
        static function () use (&$calls): never {
            $calls++;

            throw new RuntimeException;
        },
    ))->toThrow(InvalidArgumentException::class)
        ->and($calls)->toBe(0);
});
