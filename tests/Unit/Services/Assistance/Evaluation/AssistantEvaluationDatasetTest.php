<?php

declare(strict_types=1);

use Modules\AI\Services\Assistance\Evaluation\AssistantEvaluationDataset;

function assistantDatasetArray(array $o = []): array
{
    return array_replace([
        'version' => '1',
        'corpus_revision' => 'cms-1',
        'module' => 'cms',
        'data_classification' => 'synthetic',
        'cases' => [[
            'id' => 'c1', 'query' => 'how do I publish content?', 'locale' => 'en',
            'module_key' => 'cms', 'expected_surface' => 'application_content',
            'expected_citations' => ['Publishing guide'],
            'expect_clarification' => false, 'expect_refusal' => false,
            'slices' => ['publishing'],
        ]],
    ], $o);
}

it('builds a dataset from a valid array', function (): void {
    $d = AssistantEvaluationDataset::fromArray(assistantDatasetArray());
    expect($d->module)->toBe('cms')->and($d->cases)->toHaveCount(1)->and($d->cases[0]->id)->toBe('c1');
});

it('rejects an unknown top-level key', function (): void {
    expect(fn () => AssistantEvaluationDataset::fromArray(assistantDatasetArray(['x' => 1])))->toThrow(InvalidArgumentException::class);
});

it('rejects a non-synthetic classification', function (): void {
    expect(fn () => AssistantEvaluationDataset::fromArray(assistantDatasetArray(['data_classification' => 'live'])))->toThrow(InvalidArgumentException::class);
});

it('rejects an empty case list', function (): void {
    expect(fn () => AssistantEvaluationDataset::fromArray(assistantDatasetArray(['cases' => []])))->toThrow(InvalidArgumentException::class);
});

it('accepts a null module_key case', function (): void {
    $arr = assistantDatasetArray();
    $arr['cases'][0]['module_key'] = null;
    $arr['cases'][0]['expected_surface'] = 'documentation';
    expect(AssistantEvaluationDataset::fromArray($arr)->cases[0]->moduleKey)->toBeNull();
});
