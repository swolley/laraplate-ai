<?php

declare(strict_types=1);

use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationCase;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationDataset;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationService;
use Modules\Core\ApplicationContent\Data\ApplicationContentAuthorization;
use Modules\Core\ApplicationContent\Data\ApplicationContentHit;
use Modules\Core\ApplicationContent\Data\ApplicationContentQuery;
use Modules\Core\ApplicationContent\Data\ApplicationContentResult;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FiltersGroup;

function evaluationHit(string $recordKey, string $reference): ApplicationContentHit
{
    return new ApplicationContentHit(
        id: "cms.contents:{$recordKey}",
        source: 'cms.contents',
        module: 'cms',
        entity: 'contents',
        recordKey: $recordKey,
        excerpt: "Visible evidence {$recordKey}",
        label: "Evidence {$recordKey}",
        canonicalReference: $reference,
        locale: 'en',
        strategy: 'lexical',
        score: 0.9,
        revision: '1',
        truncated: false,
    );
}

function evaluationCase(
    string $id,
    array $expectedIds,
    array $expectedReferences,
    bool $authorizedEmpty,
    bool $supported,
    bool $abstain,
    string $locale = 'en',
    array $slices = [],
): ApplicationContentEvaluationCase {
    return new ApplicationContentEvaluationCase(
        id: $id,
        query: "query {$id}",
        locale: $locale,
        limit: 5,
        expectedHitIds: $expectedIds,
        expectedCitationReferences: $expectedReferences,
        expectAuthorizedEmpty: $authorizedEmpty,
        expectSupportedAnswer: $supported,
        expectAbstention: $abstain,
        slices: $slices,
        authorization: new ApplicationContentAuthorization('evaluation.contents.select', null),
    );
}

it('calculates deterministic retrieval, citation, abstention, latency, and slice metrics', function (): void {
    $dataset = new ApplicationContentEvaluationDataset(
        version: '1',
        providerVersion: 'cms-record-v1',
        corpusRevision: 'fixture-1',
        cases: [
            evaluationCase('exact', ['cms.contents:1'], ['/app/cms/contents/1'], false, true, false, slices: ['exact']),
            evaluationCase('miss', ['cms.contents:2'], ['/app/cms/contents/2'], false, true, false, slices: ['paraphrase']),
            evaluationCase('acl-empty', [], [], true, false, true, 'it', ['acl_exclusion']),
            evaluationCase('unsupported', [], [], false, false, true, slices: ['unsupported']),
        ],
    );
    $results = [
        'exact' => new ApplicationContentResult('cms.contents', [
            evaluationHit('1', '/app/cms/contents/1'),
        ], 'lexical', false),
        'miss' => new ApplicationContentResult('cms.contents', [], 'lexical', false),
        'acl-empty' => new ApplicationContentResult('cms.contents', [], 'lexical', false),
        'unsupported' => new ApplicationContentResult('cms.contents', [
            evaluationHit('3', '/app/cms/contents/3'),
        ], 'lexical', false),
    ];
    $ticks = [0.000, 0.010, 0.010, 0.030, 0.030, 0.060, 0.060, 0.100];
    $service = new ApplicationContentEvaluationService(
        clock: static function () use (&$ticks): float {
            return array_shift($ticks);
        },
    );

    $report = $service->evaluate(
        $dataset,
        'cms.contents',
        'database',
        static fn (ApplicationContentQuery $query, ApplicationContentAuthorization $authorization, ApplicationContentEvaluationCase $case): ApplicationContentResult => $results[$case->id],
    );

    expect($report['source'])->toBe('cms.contents')
        ->and($report['driver'])->toBe('database')
        ->and($report['dataset_version'])->toBe('1')
        ->and($report['provider_version'])->toBe('cms-record-v1')
        ->and($report['corpus_revision'])->toBe('fixture-1')
        ->and($report['case_count'])->toBe(4)
        ->and($report['metrics'])->toMatchArray([
            'hit_at_5' => 0.5,
            'mean_reciprocal_rank' => 0.5,
            'citation_precision' => 0.5,
            'authorized_empty_accuracy' => 1.0,
            'supported_answer_rate' => 0.5,
            'abstention_accuracy' => 0.5,
            'unavailable_rate' => 0.0,
        ])
        ->and($report['latency_ms'])->toBe([
            'average' => 25.0,
            'p50' => 20.0,
            'p95' => 40.0,
            'max' => 40.0,
        ])
        ->and($report['slices']['locale']['it']['authorized_empty_accuracy'])->toBe(1.0)
        ->and($report['slices']['category']['exact']['hit_at_5'])->toBe(1.0)
        ->and($report)->not->toHaveKeys(['queries', 'prompts', 'scores', 'authorizations']);
});

it('treats provider failures as unavailable evidence without exposing exception details', function (): void {
    $dataset = new ApplicationContentEvaluationDataset('1', 'provider-1', 'fixture-1', [
        evaluationCase('failure', [], [], true, false, true),
    ]);
    $service = new ApplicationContentEvaluationService;

    $report = $service->evaluate(
        $dataset,
        'cms.contents',
        'database',
        static fn (): never => throw new RuntimeException('database credentials leaked'),
    );

    expect($report['metrics']['unavailable_rate'])->toBe(1.0)
        ->and($report['metrics']['abstention_accuracy'])->toBe(1.0)
        ->and(json_encode($report))->not->toContain('credentials leaked');
});

it('rejects invalid evaluation cases and source mismatches before retrieval', function (): void {
    expect(fn () => evaluationCase('bad id', [], [], false, false, true))
        ->toThrow(InvalidArgumentException::class);

    $dataset = new ApplicationContentEvaluationDataset('1', 'provider-1', 'fixture-1', [
        evaluationCase('exact', ['cms.contents:1'], ['/app/cms/contents/1'], false, true, false),
    ]);
    $calls = 0;

    expect(fn () => (new ApplicationContentEvaluationService)->evaluate(
        $dataset,
        'erp.orders',
        'database',
        static function () use (&$calls): never {
            $calls++;

            throw new RuntimeException;
        },
    ))->toThrow(InvalidArgumentException::class)
        ->and($calls)->toBe(0);
});

it('loads only typed evaluation cases and ACL filters from JSON', function (): void {
    $dataset = ApplicationContentEvaluationDataset::fromArray([
        'source' => 'cms.contents',
        'data_classification' => 'synthetic',
        'version' => '1',
        'provider_version' => 'cms-record-v1',
        'corpus_revision' => 'fixture-1',
        'cases' => [[
            'id' => 'acl-visible',
            'query' => 'visible content',
            'locale' => 'en',
            'limit' => 5,
            'expected_hit_ids' => ['cms.contents:1'],
            'expected_citation_references' => ['/app/cms/contents/1'],
            'expect_authorized_empty' => false,
            'expect_supported_answer' => true,
            'expect_abstention' => false,
            'slices' => ['acl_visible'],
            'authorization' => [
                'permission' => 'evaluation.contents.select',
                'filters' => [
                    'operator' => 'and',
                    'filters' => [[
                        'property' => 'id',
                        'operator' => '=',
                        'value' => 1,
                    ]],
                ],
            ],
        ]],
    ]);

    expect($dataset->source)->toBe('cms.contents')
        ->and($dataset->cases[0]->authorization->filters)->toBeInstanceOf(FiltersGroup::class)
        ->and($dataset->cases[0]->authorization->filters?->filters[0])->toBeInstanceOf(Filter::class)
        ->and($dataset->cases[0]->authorization->filters?->filters[0]->property)->toBe('id');

    expect(fn () => ApplicationContentEvaluationDataset::fromArray([
        'source' => 'cms.contents',
        'data_classification' => 'synthetic',
        'version' => '1',
        'provider_version' => 'cms-record-v1',
        'corpus_revision' => 'fixture-1',
        'cases' => [],
        'system_prompt' => 'forbidden',
    ]))->toThrow(InvalidArgumentException::class);
});
