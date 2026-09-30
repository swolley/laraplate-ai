<?php

declare(strict_types=1);

use Modules\AI\Services\ApplicationContent\Evaluation\Contracts\PerStrategyEngineRetrieverInterface;
use Modules\AI\Tests\Stubs\ApplicationContent\FakePerStrategyEngineRetriever;
use Modules\AI\Tests\Stubs\ApplicationContent\RetrievalStrategyCommandContentProvider;
use Modules\Core\ApplicationContent\ApplicationContentRetrievalProviderRegistry;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderRegistryInterface;
use Modules\Core\Models\User;
use Modules\Core\Search\Contracts\ITextEmbedder;
use Modules\Core\Search\DTOs\AdvancedSearchResult;

/**
 * @param  list<string>  $orderedIds
 * @return array<string, array{id: string, score: float, raw_score: float, score_details: array<never>, source: array<never>, rank: int}>
 */
function tuneCommandRanking(array $orderedIds): array
{
    $ranked = [];

    foreach ($orderedIds as $index => $id) {
        $ranked[$id] = ['id' => $id, 'score' => 1.0, 'raw_score' => 1.0, 'score_details' => [], 'source' => [], 'rank' => $index + 1];
    }

    return $ranked;
}

/**
 * @param  list<string>  $finalIds
 * @param  array<string, mixed>  $meta
 */
function tuneCommandResult(array $finalIds, array $meta = []): AdvancedSearchResult
{
    $hits = array_map(static fn (string $id): array => ['id' => $id, 'score' => 1.0, 'raw_score' => 1.0, 'score_details' => [], 'source' => []], $finalIds);

    return new AdvancedSearchResult(hits: $hits, total: count($hits), page: 1, perPage: 5, totalPages: 1, meta: $meta);
}

/**
 * Registers the fake source, retriever and embedder, and writes a one-case dataset.
 *
 * @return array{directory: string, dataset: string, output: string, retriever: FakePerStrategyEngineRetriever}
 */
function tuneCommandFixture(): array
{
    $registry = new ApplicationContentRetrievalProviderRegistry;
    $registry->register(new RetrievalStrategyCommandContentProvider(User::class));
    app()->instance(ApplicationContentRetrievalProviderRegistryInterface::class, $registry);

    $retriever = new FakePerStrategyEngineRetriever(
        tuneCommandResult(['1', '2'], ['per_strategy' => [
            'keyword' => tuneCommandRanking(['1', '2']),
            'vector' => tuneCommandRanking(['2', '1']),
            'hybrid' => tuneCommandRanking(['2', '1']),
        ]]),
        tuneCommandResult(['2', '1']),
    );
    app()->instance(PerStrategyEngineRetrieverInterface::class, $retriever);

    $embedder = Mockery::mock(ITextEmbedder::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2, 0.3]);
    app()->instance(ITextEmbedder::class, $embedder);

    $directory = sys_get_temp_dir() . '/laraplate-ai-tune-retrieval-' . bin2hex(random_bytes(5));
    mkdir($directory, 0700, true);
    $dataset = $directory . '/dataset.json';
    file_put_contents($dataset, json_encode([
        'source' => 'cms.strategy_records',
        'data_classification' => 'synthetic',
        'version' => '1',
        'provider_version' => 'fake-v1',
        'corpus_revision' => 'generated-1',
        'cases' => [[
            'id' => 'names',
            'query' => 'Mario Rossi',
            'locale' => 'en',
            'limit' => 5,
            'expected_hit_ids' => ['cms.strategy_records:2'],
            'expected_citation_references' => [],
            'expect_authorized_empty' => false,
            'expect_supported_answer' => false,
            'expect_abstention' => false,
            'slices' => [],
            'authorization' => ['permission' => 'evaluation.contents.select', 'filters' => null],
        ]],
    ], JSON_THROW_ON_ERROR));

    return ['directory' => $directory, 'dataset' => $dataset, 'output' => $directory . '/report.json', 'retriever' => $retriever];
}

function tuneCommandCleanup(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($directory);
}

it('fails before tuning for an unregistered source', function (): void {
    app()->instance(ApplicationContentRetrievalProviderRegistryInterface::class, new ApplicationContentRetrievalProviderRegistry);

    $this->artisan('ai:tune-retrieval', [
        '--dataset' => '/missing/dataset.json',
        '--source' => 'missing.records',
        '--output' => '/tmp/missing-tuning-report.json',
    ])->assertFailed();
});

it('fails for an unknown metric or grid without writing a report', function (array $options): void {
    $fixture = tuneCommandFixture();

    try {
        $this->artisan('ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
            ...$options,
        ])->assertFailed();

        expect(file_exists($fixture['output']))->toBeFalse();
    } finally {
        tuneCommandCleanup($fixture['directory']);
    }
})->with([
    'metric' => [['--metric' => 'ndcg_at_7']],
    'grid' => [['--grid' => 'exhaustive']],
]);

it('writes only its report, prints a profile block and refuses to overwrite without force', function (): void {
    $fixture = tuneCommandFixture();
    $profile_path = module_path('Core', 'config/search_tuning.php');
    $profile_hash = hash_file('sha256', $profile_path);

    try {
        $this->artisan('ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
        ])
            ->expectsOutputToContain("'default' => [")
            ->expectsOutputToContain("'classes' => [")
            ->assertSuccessful();

        $report = json_decode((string) file_get_contents($fixture['output']), true, flags: JSON_THROW_ON_ERROR);

        expect($report['source'])->toBe('cms.strategy_records')
            ->and($report['metric'])->toBe('ndcg_at_5')
            ->and($report['case_count'])->toBe(1)
            ->and($report['class_counts'])->toBe(['short_keyword' => 1])
            ->and($report['candidates'])->toHaveCount(108)
            ->and($report['winner']['params'])->toHaveKeys(['keyword_weight', 'vector_weight', 'hybrid_weight'])
            ->and($fixture['retriever']->calls)->toHaveCount(2)
            ->and(array_map(basename(...), glob($fixture['directory'] . '/*') ?: []))->toBe(['dataset.json', 'report.json'])
            ->and(hash_file('sha256', $profile_path))->toBe($profile_hash);

        $this->artisan('ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
        ])->assertFailed();
    } finally {
        tuneCommandCleanup($fixture['directory']);
    }
});
