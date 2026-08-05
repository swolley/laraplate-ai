<?php

declare(strict_types=1);

use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationDataset;

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

it('builds a dataset from a valid array', function (): void {
    $dataset = DocumentationEvaluationDataset::fromArray(docDatasetArray());

    expect($dataset->module)->toBe('core')
        ->and($dataset->indexProfile)->toBe('user')
        ->and($dataset->cases)->toHaveCount(1)
        ->and($dataset->cases[0]->id)->toBe('exact');
});

it('rejects an unknown top-level key', function (): void {
    expect(fn () => DocumentationEvaluationDataset::fromArray(docDatasetArray(['surprise' => 1])))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an unknown index profile', function (): void {
    expect(fn () => DocumentationEvaluationDataset::fromArray(docDatasetArray(['index_profile' => 'admin'])))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an empty case list', function (): void {
    expect(fn () => DocumentationEvaluationDataset::fromArray(docDatasetArray(['cases' => []])))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a non-synthetic classification', function (): void {
    expect(fn () => DocumentationEvaluationDataset::fromArray(docDatasetArray(['data_classification' => 'live'])))
        ->toThrow(InvalidArgumentException::class);
});
