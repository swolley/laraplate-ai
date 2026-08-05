<?php

declare(strict_types=1);

use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationDataset;
use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationService;
use Modules\AI\Tests\Stubs\Documentation\FakeDocumentationSearch;

function docDatasetArray(array $overrides = []): array
{
    return array_replace([
        'version' => '1',
        'corpus_revision' => 'core-1',
        'module' => 'core',
        'index_profile' => 'user',
        'data_classification' => 'synthetic',
        'cases' => [[
            'id' => 'exact',
            'query' => 'how do I export a grid?',
            'locale' => 'en',
            'top_k' => 5,
            'expected_source_labels' => ['Core · Grid export'],
            'expected_citation_labels' => ['Core · Grid export'],
            'expect_authorized_empty' => false,
            'expect_supported_answer' => true,
            'expect_refusal' => false,
            'slices' => ['grid', 'single_hop'],
            'tenant_scope' => 'global',
            'tenant_id' => null,
            'effective_permissions' => [],
        ]],
    ], $overrides);
}

beforeEach(function (): void {
    config()->set('ai.features.faq.max_documents', 5);
    config()->set('ai.features.faq.policy_classification_version', 'in-app-docs-v1');
});

it('scores a perfect hit, a citation, and a correct refusal', function (): void {
    $dataset = DocumentationEvaluationDataset::fromArray(docDatasetArray([
        'cases' => [
            [
                'id' => 'hit', 'query' => 'how do I export a grid?', 'locale' => 'en', 'top_k' => 5,
                'expected_source_labels' => ['Core · Grid export'],
                'expected_citation_labels' => ['Core · Grid export'],
                'expect_authorized_empty' => false, 'expect_supported_answer' => true, 'expect_refusal' => false,
                'slices' => ['grid'], 'tenant_scope' => 'global', 'tenant_id' => null, 'effective_permissions' => [],
            ],
            [
                'id' => 'refuse', 'query' => 'what is the weather?', 'locale' => 'en', 'top_k' => 5,
                'expected_source_labels' => [], 'expected_citation_labels' => [],
                'expect_authorized_empty' => false, 'expect_supported_answer' => false, 'expect_refusal' => true,
                'slices' => ['off_topic'], 'tenant_scope' => 'global', 'tenant_id' => null, 'effective_permissions' => [],
            ],
        ],
    ]));

    $retrieval = FakeDocumentationSearch::forInAppRetrieval([
        'how do I export a grid?' => [
            FakeDocumentationSearch::document('Core · Grid export', 'en', 'Use export.', ['Core', 'Grid export']),
        ],
        'what is the weather?' => [],
    ]);

    $service = new DocumentationEvaluationService;
    $report = $service->evaluate(
        $dataset,
        'fake',
        static fn (string $q, $access) => $retrieval->retrieve($q, $access),
    );

    expect($report['metrics']['source_hit_at_k'])->toBe(1.0)
        ->and($report['metrics']['mean_reciprocal_rank'])->toBe(1.0)
        ->and($report['metrics']['citation_precision'])->toBe(1.0)
        ->and($report['metrics']['refusal_accuracy'])->toBe(1.0)
        ->and($report['module'])->toBe('core')
        ->and($report['index_profile'])->toBe('user')
        ->and(json_encode($report))->not->toContain('what is the weather?');
});

it('counts a retrieval that throws as unavailable', function (): void {
    $dataset = DocumentationEvaluationDataset::fromArray(docDatasetArray());
    $service = new DocumentationEvaluationService;

    $report = $service->evaluate($dataset, 'fake', static function (): array {
        throw new RuntimeException('down');
    });

    expect($report['metrics']['unavailable_rate'])->toBe(1.0);
});
