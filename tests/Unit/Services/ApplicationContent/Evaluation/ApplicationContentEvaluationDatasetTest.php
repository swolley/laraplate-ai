<?php

declare(strict_types=1);

use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationDataset;

/**
 * A dataset built from a real corpus carries `private`: it holds queries about content nobody may
 * redistribute, so it must live where git cannot see it. The loader enforces that for files.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function evaluation_dataset_array(array $overrides = []): array
{
    return [
        'source' => 'cms.contents',
        'data_classification' => 'private',
        'version' => '1',
        'provider_version' => 'cms-record-v1',
        'corpus_revision' => 'archive-sample-1',
        'cases' => [[
            'id' => 'case-1',
            'query' => 'a query',
            'locale' => 'it',
            'limit' => 5,
            'expected_hit_ids' => ['cms.contents:1'],
            'expected_citation_references' => [],
            'expect_authorized_empty' => false,
            'expect_supported_answer' => false,
            'expect_abstention' => false,
            'slices' => [],
            'authorization' => ['permission' => 'evaluation.contents.select', 'filters' => null],
        ]],
        ...$overrides,
    ];
}

function evaluation_dataset_write(string $directory, array $data): string
{
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }

    $path = $directory . '/dataset-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));

    return $path;
}

afterEach(function (): void {
    foreach (['evaluation-dataset-outside', 'evaluation-dataset-inside'] as $name) {
        $directory = $name === 'evaluation-dataset-outside'
            ? sys_get_temp_dir() . '/' . $name
            : storage_path('framework/testing/' . $name);

        foreach (glob($directory . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }
});

it('accepts the synthetic and private classifications and nothing else', function (string $classification, bool $accepted): void {
    $load = fn (): ApplicationContentEvaluationDataset => ApplicationContentEvaluationDataset::fromArray(
        evaluation_dataset_array(['data_classification' => $classification]),
    );

    if ($accepted) {
        expect($load()->dataClassification)->toBe($classification);

        return;
    }

    expect($load)->toThrow(InvalidArgumentException::class);
})->with([
    'synthetic' => ['synthetic', true],
    'private' => ['private', true],
    'public' => ['public', false],
    'empty' => ['', false],
]);

it('loads a private dataset kept outside the project', function (): void {
    $path = evaluation_dataset_write(sys_get_temp_dir() . '/evaluation-dataset-outside', evaluation_dataset_array());

    $dataset = ApplicationContentEvaluationDataset::fromFile($path);

    expect($dataset->dataClassification)->toBe('private')
        ->and($dataset->cases)->toHaveCount(1);
});

it('refuses a private dataset inside the project, where it could be committed', function (): void {
    $path = evaluation_dataset_write(storage_path('framework/testing/evaluation-dataset-inside'), evaluation_dataset_array());

    expect(fn () => ApplicationContentEvaluationDataset::fromFile($path))
        ->toThrow(InvalidArgumentException::class, 'outside the project');
});

it('refuses a private dataset reached through a link from outside the project', function (): void {
    $target = evaluation_dataset_write(storage_path('framework/testing/evaluation-dataset-inside'), evaluation_dataset_array());
    $outside = sys_get_temp_dir() . '/evaluation-dataset-outside';
    mkdir($outside, 0700, true);
    $link = $outside . '/link.json';
    symlink($target, $link);

    expect(fn () => ApplicationContentEvaluationDataset::fromFile($link))
        ->toThrow(InvalidArgumentException::class, 'outside the project');
});

it('still loads a synthetic dataset from inside the project, as the committed fixtures are', function (): void {
    $path = evaluation_dataset_write(
        storage_path('framework/testing/evaluation-dataset-inside'),
        evaluation_dataset_array(['data_classification' => 'synthetic']),
    );

    expect(ApplicationContentEvaluationDataset::fromFile($path)->dataClassification)->toBe('synthetic');
});
