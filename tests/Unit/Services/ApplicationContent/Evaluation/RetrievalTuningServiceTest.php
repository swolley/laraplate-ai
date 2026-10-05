<?php

declare(strict_types=1);

use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationCase;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationDataset;
use Modules\AI\Services\ApplicationContent\Evaluation\RetrievalTuningSafeguards;
use Modules\AI\Services\ApplicationContent\Evaluation\RetrievalTuningService;
use Modules\Core\ApplicationContent\Data\ApplicationContentAuthorization;
use Modules\Core\Search\DTOs\AdvancedSearchResult;

function retrievalTuningCase(string $id, string $query): ApplicationContentEvaluationCase
{
    return new ApplicationContentEvaluationCase(
        id: $id,
        query: $query,
        locale: 'en',
        limit: 5,
        expectedHitIds: ['cms.contents:3'],
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
function retrievalTuningRanking(array $orderedIds): array
{
    $ranked = [];

    foreach ($orderedIds as $index => $id) {
        $ranked[$id] = ['id' => $id, 'score' => 1.0, 'raw_score' => 1.0, 'score_details' => [], 'source' => [], 'rank' => $index + 1];
    }

    return $ranked;
}

/**
 * @param  list<string>  $finalIds
 */
function retrievalTuningResult(array $finalIds, bool $withStrategies): AdvancedSearchResult
{
    $hits = array_map(static fn (string $id): array => ['id' => $id, 'score' => 1.0, 'raw_score' => 1.0, 'score_details' => [], 'source' => []], $finalIds);

    return new AdvancedSearchResult(
        hits: $hits,
        total: count($hits),
        page: 1,
        perPage: 5,
        totalPages: 1,
        meta: $withStrategies ? ['per_strategy' => [
            'keyword' => retrievalTuningRanking(['1', '2']),
            'vector' => retrievalTuningRanking(['2', '3']),
            'hybrid' => retrievalTuningRanking(['2', '1']),
        ]] : [],
    );
}

it('ranks grid candidates by the chosen metric while retrieving every case once per reranker pass', function (): void {
    $dataset = new ApplicationContentEvaluationDataset(
        version: '1',
        providerVersion: 'p',
        corpusRevision: 'c',
        cases: [
            retrievalTuningCase('identifier', 'fattura INV-1042'),
            retrievalTuningCase('short', 'Mario Rossi'),
            retrievalTuningCase('sentence', 'come faccio ad annullare una fattura già inviata'),
        ],
    );
    $keyword_only = ['keyword_weight' => 1.0, 'vector_weight' => 0.0, 'hybrid_weight' => 0.0];
    $vector_only = ['keyword_weight' => 0.0, 'vector_weight' => 1.0, 'hybrid_weight' => 0.0];
    $calls = [];

    $report = app(RetrievalTuningService::class)->tune(
        $dataset,
        'cms.contents',
        [$keyword_only, $vector_only],
        'ndcg_at_5',
        static function (ApplicationContentEvaluationCase $case, bool $useReranker) use (&$calls): AdvancedSearchResult {
            $calls[] = [$case->id, $useReranker];

            return retrievalTuningResult($useReranker ? ['3', '2', '1'] : ['2', '1', '3'], ! $useReranker);
        },
    );

    expect($calls)->toBe([
        ['identifier', false], ['identifier', true],
        ['short', false], ['short', true],
        ['sentence', false], ['sentence', true],
    ])
        ->and($report['metric'])->toBe('ndcg_at_5')
        ->and($report['case_count'])->toBe(3)
        ->and($report['class_counts'])->toBe(['identifier' => 1, 'short_keyword' => 1, 'natural_language' => 1])
        ->and(array_column($report['candidates'], 'params'))->toBe([$vector_only, $keyword_only])
        ->and($report['candidates'][0]['metrics']['ndcg_at_5'])->toBe(0.6309)
        ->and($report['candidates'][1]['metrics']['ndcg_at_5'])->toBe(0.5)
        ->and($report['candidates'][0]['per_class_metrics'])->toHaveKeys(['identifier', 'short_keyword', 'natural_language'])
        ->and($report['candidates'][0]['delta_vs_committed'])->toHaveKey('ndcg_at_5')
        ->and($report['winner']['params'])->toBe($vector_only)
        ->and($report['reranked']['metrics']['ndcg_at_5'])->toBe(1.0)
        ->and($report['committed'])->toHaveKeys(['profile_version', 'metrics', 'per_class_metrics']);
});

it('rejects an unknown metric and a candidate outside the fusion parameters', function (string $metric, array $candidate): void {
    $dataset = new ApplicationContentEvaluationDataset(version: '1', providerVersion: 'p', corpusRevision: 'c', cases: [retrievalTuningCase('short', 'Mario Rossi')]);

    app(RetrievalTuningService::class)->tune($dataset, 'cms.contents', [$candidate], $metric, static fn (): AdvancedSearchResult => retrievalTuningResult([], true));
})->with([
    'unknown metric' => ['ndcg_at_7', ['rrf_k' => 60]],
    'ranking parameter' => ['ndcg_at_5', ['rerank_blend' => 0.5]],
    'out of range weight' => ['ndcg_at_5', ['keyword_weight' => 1.7]],
])->throws(InvalidArgumentException::class);

it('prints the winner as a profile block ready to paste', function (): void {
    $block = app(RetrievalTuningService::class)->profileBlock([
        'winner' => ['params' => ['keyword_weight' => 0.5, 'vector_weight' => 0.2, 'hybrid_weight' => 0.3, 'rrf_k' => 60]],
        'class_winners' => ['identifier' => ['keyword_weight' => 1.0, 'vector_weight' => 0.0, 'hybrid_weight' => 0.0]],
    ]);

    expect($block)->toContain("'default' => [")
        ->and($block)->toContain("'report' => 'docs/evaluations/retrieval-tuning/")
        ->and($block)->toContain("'keyword_weight' => 0.5,")
        ->and($block)->toContain("'rrf_k' => 60,")
        ->and($block)->toContain("'identifier' => [")
        ->and($block)->toContain("'multi_term' => [],");
});

/**
 * Cases whose expected hit is chosen per bucket, to make the keyword and vector orderings win on
 * different classes: identifier queries want id 1 (the keyword ranking), the rest want id 3 (vector).
 *
 * @return list<ApplicationContentEvaluationCase>
 */
function retrievalTuningMixedCases(): array
{
    $identifier = retrievalTuningCase('identifier-0', 'fattura INV-1042');
    $identifier = new ApplicationContentEvaluationCase(
        id: $identifier->id,
        query: $identifier->query,
        locale: 'en',
        limit: 5,
        expectedHitIds: ['cms.contents:1'],
        expectedCitationReferences: [],
        expectAuthorizedEmpty: false,
        expectSupportedAnswer: false,
        expectAbstention: false,
        slices: [],
        authorization: $identifier->authorization,
    );

    return [
        $identifier,
        retrievalTuningCase('short-0', 'Mario Rossi'),
        retrievalTuningCase('short-1', 'Anna Bianchi'),
        retrievalTuningCase('short-2', 'Luca Verdi'),
    ];
}

function retrievalTuningTune(array $cases, ?RetrievalTuningSafeguards $safeguards = null): array
{
    $keyword_only = ['keyword_weight' => 1.0, 'vector_weight' => 0.0, 'hybrid_weight' => 0.0];
    $vector_only = ['keyword_weight' => 0.0, 'vector_weight' => 1.0, 'hybrid_weight' => 0.0];

    return app(RetrievalTuningService::class)->tune(
        new ApplicationContentEvaluationDataset(version: '1', providerVersion: 'p', corpusRevision: 'c', cases: $cases),
        'cms.contents',
        [$vector_only, $keyword_only],
        'ndcg_at_5',
        static fn (ApplicationContentEvaluationCase $case, bool $useReranker): AdvancedSearchResult => retrievalTuningResult($useReranker ? ['3', '2', '1'] : ['2', '1', '3'], ! $useReranker),
        $safeguards,
    );
}

it('keeps the old behaviour with the default safeguards: a class winner needs only to beat the overall winner', function (): void {
    $report = retrievalTuningTune(retrievalTuningMixedCases());

    expect($report['class_winners'])->toHaveKey('identifier')
        ->and($report['class_winners']['identifier']['keyword_weight'])->toBe(1.0)
        ->and($report['class_winners_skipped'])->toBe([])
        ->and($report['validation']['status'])->toBe('disabled');
});

it('does not let a class with too few cases override the overall winner', function (): void {
    $report = retrievalTuningTune(retrievalTuningMixedCases(), new RetrievalTuningSafeguards(minClassCases: 2));

    expect($report['class_winners'])->toBe([])
        ->and($report['class_winners_skipped'])->toBe(['identifier' => 'too_few_cases']);
});

it('does not let a class override the overall winner by less than the required margin', function (): void {
    $report = retrievalTuningTune(retrievalTuningMixedCases(), new RetrievalTuningSafeguards(classMargin: 1.0));

    expect($report['class_winners'])->toBe([])
        ->and($report['class_winners_skipped'])->toBe(['identifier' => 'margin']);
});

/**
 * @return array{train: list<string>, holdout: list<string>}
 */
function retrievalTuningBuckets(int $count, float $fraction): array
{
    $buckets = ['train' => [], 'holdout' => []];

    for ($i = 0; $i < $count; $i++) {
        $id = "case-{$i}";
        $buckets[(crc32($id) % 1000) < (int) round($fraction * 1000) ? 'holdout' : 'train'][] = $id;
    }

    return $buckets;
}

it('splits the dataset deterministically and validates the winner on the held-out cases', function (): void {
    $buckets = retrievalTuningBuckets(20, 0.3);
    $cases = array_map(static fn (string $id): ApplicationContentEvaluationCase => retrievalTuningCase($id, 'Mario Rossi'), [...$buckets['train'], ...$buckets['holdout']]);

    $first = retrievalTuningTune($cases, new RetrievalTuningSafeguards(holdoutFraction: 0.3));
    $second = retrievalTuningTune(array_reverse($cases), new RetrievalTuningSafeguards(holdoutFraction: 0.3));

    expect($first['case_count'])->toBe(20)
        ->and($first['holdout_case_count'])->toBe(count($buckets['holdout']))
        ->and($first['train_case_count'])->toBe(count($buckets['train']))
        ->and($first['validation']['status'])->toBe('passed')
        ->and($first['winner']['params']['vector_weight'])->toBe(1.0)
        ->and($second['holdout_case_count'])->toBe($first['holdout_case_count'])
        ->and($second['validation']['status'])->toBe('passed');
});

it('declares no winner when the best candidate on the training cases loses on the held-out ones', function (): void {
    $buckets = retrievalTuningBuckets(20, 0.3);
    $train = array_map(static fn (string $id): ApplicationContentEvaluationCase => retrievalTuningCase($id, 'Mario Rossi'), $buckets['train']);
    $holdout = array_map(static function (string $id): ApplicationContentEvaluationCase {
        $case = retrievalTuningCase($id, 'Mario Rossi');

        return new ApplicationContentEvaluationCase(
            id: $case->id,
            query: $case->query,
            locale: 'en',
            limit: 5,
            expectedHitIds: ['cms.contents:1'],
            expectedCitationReferences: [],
            expectAuthorizedEmpty: false,
            expectSupportedAnswer: false,
            expectAbstention: false,
            slices: [],
            authorization: $case->authorization,
        );
    }, $buckets['holdout']);

    $report = retrievalTuningTune([...$train, ...$holdout], new RetrievalTuningSafeguards(holdoutFraction: 0.3));

    expect($report['validation']['status'])->toBe('failed')
        ->and($report['winner'])->toBeNull()
        ->and($report['validation']['rejected_params']['vector_weight'])->toBe(1.0)
        ->and($report['class_winners'])->toBe([]);

    $block = app(RetrievalTuningService::class)->profileBlock($report);

    expect($block)->toContain('keep the committed profile')
        ->and($block)->not->toContain("'default' =>");
});

it('skips the validation, and says so, when the split leaves no held-out case', function (): void {
    $buckets = retrievalTuningBuckets(6, 0.5);
    $cases = array_map(static fn (string $id): ApplicationContentEvaluationCase => retrievalTuningCase($id, 'Mario Rossi'), array_slice($buckets['train'], 0, 2));

    $report = retrievalTuningTune($cases, new RetrievalTuningSafeguards(holdoutFraction: 0.5));

    expect($report['validation']['status'])->toBe('skipped')
        ->and($report['validation']['reason'])->toBe('no_held_out_cases')
        ->and($report['winner'])->not->toBeNull()
        ->and(app(RetrievalTuningService::class)->profileBlock($report))->toContain('not validated');
});

it('rejects safeguards outside their range', function (array $arguments): void {
    new RetrievalTuningSafeguards(...$arguments);
})->with([
    'holdout above one half' => [['holdoutFraction' => 0.6]],
    'negative holdout' => [['holdoutFraction' => -0.1]],
    'no class cases' => [['minClassCases' => 0]],
    'margin above one' => [['classMargin' => 1.5]],
    'negative noise margin' => [['noiseMargin' => -0.1]],
    'noise margin above one' => [['noiseMargin' => 1.5]],
])->throws(InvalidArgumentException::class);

it('resolves the noise margin: off, fixed, or one case of the sample with a floor', function (): void {
    expect((new RetrievalTuningSafeguards)->tolerance(10))->toBe(0.0)
        ->and((new RetrievalTuningSafeguards(noiseMargin: 0.2))->tolerance(10))->toBe(0.2)
        ->and((new RetrievalTuningSafeguards(noiseMargin: null))->tolerance(4))->toBe(0.25)
        ->and((new RetrievalTuningSafeguards(noiseMargin: null))->tolerance(500))->toBe(0.01)
        ->and(RetrievalTuningSafeguards::recommended()->noiseMargin)->toBeNull()
        ->and((new RetrievalTuningSafeguards(noiseMargin: null))->toArray()['noise_margin'])->toBe('auto');
});

/**
 * @return list<ApplicationContentEvaluationCase>
 */
function retrievalTuningShortCases(int $count): array
{
    return array_map(static fn (int $i): ApplicationContentEvaluationCase => retrievalTuningCase("short-{$i}", 'Mario Rossi'), range(0, $count - 1));
}

it('keeps the winner when it beats the committed profile by more than the noise margin', function (): void {
    $unguarded = retrievalTuningTune(retrievalTuningShortCases(6));
    $gain = $unguarded['candidates'][0]['delta_vs_committed']['ndcg_at_5'];

    expect($gain)->toBeGreaterThan(0.05)
        ->and($unguarded['noise']['status'])->toBe('disabled');

    $report = retrievalTuningTune(retrievalTuningShortCases(6), new RetrievalTuningSafeguards(noiseMargin: $gain - 0.01));

    expect($report['winner'])->not->toBeNull()
        ->and($report['noise']['status'])->toBe('passed')
        ->and($report['noise']['gain'])->toBe($gain)
        ->and($report['noise']['margin'])->toBe(round($gain - 0.01, 4))
        ->and($report['noise']['selection_cases'])->toBe(6);
});

it('declares no winner when the best candidate beats the committed profile by no more than the noise margin', function (): void {
    $gain = retrievalTuningTune(retrievalTuningShortCases(6))['candidates'][0]['delta_vs_committed']['ndcg_at_5'];

    $report = retrievalTuningTune(retrievalTuningShortCases(6), new RetrievalTuningSafeguards(noiseMargin: $gain));

    expect($report['winner'])->toBeNull()
        ->and($report['noise']['status'])->toBe('within_noise')
        ->and($report['class_winners'])->toBe([])
        ->and($report['candidates'])->toHaveCount(2);

    $block = app(RetrievalTuningService::class)->profileBlock($report);

    expect($block)->toContain('noise margin')
        ->and($block)->toContain('keep the committed profile')
        ->and($block)->not->toContain("'default' =>");
});

it('sizes the automatic margin on the selection cases: one case of the sample, never below the floor', function (int $count, float $margin): void {
    $report = retrievalTuningTune(retrievalTuningShortCases($count), new RetrievalTuningSafeguards(noiseMargin: null));

    expect($report['noise']['margin'])->toBe($margin)
        ->and($report['noise']['selection_cases'])->toBe($count);
})->with([
    'four cases' => [4, 0.25],
    'forty cases' => [40, 0.025],
    'past the floor' => [150, 0.01],
]);

/**
 * Many short cases that the vector ranking serves, so the overall winner clears the noise margin,
 * plus identifier cases the keyword ranking serves better: on those a keyword-only candidate gains
 * 0.1309 of ndcg_at_5 over the vector winner, which is more than one case of the class only from
 * eight cases up (1 / 8 = 0.125).
 *
 * @return list<ApplicationContentEvaluationCase>
 */
function retrievalTuningLopsidedCases(int $identifiers): array
{
    $cases = retrievalTuningShortCases(40);

    for ($i = 0; $i < $identifiers; $i++) {
        $identifier = retrievalTuningCase("identifier-{$i}", 'fattura INV-1042');
        $cases[] = new ApplicationContentEvaluationCase(
            id: $identifier->id,
            query: $identifier->query,
            locale: 'en',
            limit: 5,
            expectedHitIds: ['cms.contents:1'],
            expectedCitationReferences: [],
            expectAuthorizedEmpty: false,
            expectSupportedAnswer: false,
            expectAbstention: false,
            slices: [],
            authorization: $identifier->authorization,
        );
    }

    return $cases;
}

it('does not let a class override the winner by what one of its cases could move', function (): void {
    $report = retrievalTuningTune(retrievalTuningLopsidedCases(7), new RetrievalTuningSafeguards(minClassCases: 1, noiseMargin: null));

    expect($report['noise']['status'])->toBe('passed')
        ->and($report['class_winners'])->toBe([])
        ->and($report['class_winners_skipped'])->toBe(['identifier' => 'within_noise']);
});

it('lets a class override the winner once its gain clears the noise of its own cases', function (): void {
    $report = retrievalTuningTune(retrievalTuningLopsidedCases(8), new RetrievalTuningSafeguards(minClassCases: 1, noiseMargin: null));

    expect($report['class_winners'])->toHaveKey('identifier')
        ->and($report['class_winners']['identifier']['keyword_weight'])->toBe(1.0)
        ->and($report['class_winners_skipped'])->toBe([]);
});

it('reports how many cases the reranker ran for, so a down service is not read as a reranker that adds nothing', function (bool $reranked, string $status, int $ran): void {
    $cases = array_map(static fn (int $i): ApplicationContentEvaluationCase => retrievalTuningCase("rr-{$i}", 'Mario Rossi'), range(0, 3));

    $report = app(RetrievalTuningService::class)->tune(
        new ApplicationContentEvaluationDataset(version: '1', providerVersion: 'p', corpusRevision: 'c', cases: $cases),
        'cms.contents',
        [['keyword_weight' => 1.0, 'vector_weight' => 0.0, 'hybrid_weight' => 0.0]],
        'ndcg_at_5',
        static function (ApplicationContentEvaluationCase $case, bool $useReranker) use ($reranked): AdvancedSearchResult {
            $result = retrievalTuningResult(['2', '1', '3'], ! $useReranker);

            return $useReranker && $reranked
                ? new AdvancedSearchResult(hits: $result->hits, total: $result->total, page: 1, perPage: 5, totalPages: 1, meta: ['reranked' => true])
                : $result;
        },
    );

    expect($report['reranker'])->toBe(['requested' => 4, 'ran' => $ran, 'status' => $status, 'model' => null]);
})->with([
    'the reranker ran' => [true, 'ran', 4],
    'the reranker did not run' => [false, 'not_run', 0],
]);

it('reports the model that scored the reranked cases', function (): void {
    $cases = array_map(static fn (int $i): ApplicationContentEvaluationCase => retrievalTuningCase("rm-{$i}", 'Mario Rossi'), range(0, 3));

    $report = app(RetrievalTuningService::class)->tune(
        new ApplicationContentEvaluationDataset(version: '1', providerVersion: 'p', corpusRevision: 'c', cases: $cases),
        'cms.contents',
        [['keyword_weight' => 1.0, 'vector_weight' => 0.0, 'hybrid_weight' => 0.0]],
        'ndcg_at_5',
        static function (ApplicationContentEvaluationCase $case, bool $useReranker): AdvancedSearchResult {
            $result = retrievalTuningResult(['2', '1', '3'], ! $useReranker);

            return $useReranker
                ? new AdvancedSearchResult(hits: $result->hits, total: $result->total, page: 1, perPage: 5, totalPages: 1, meta: ['reranked' => true, 'reranker_model' => 'org/reranker'])
                : $result;
        },
    );

    expect($report['reranker']['model'])->toBe('org/reranker');
});
