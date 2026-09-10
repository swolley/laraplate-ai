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
 * Builds a rank-ordered per-strategy hit map, mirroring what
 * `EnsembleSearchService` writes into `AdvancedSearchResult::$meta['per_strategy']`.
 *
 * @param  list<string>  $orderedIds
 * @return array<string, array{id: string, score: float, raw_score: float, score_details: array<never>, source: array<never>, rank: int}>
 */
function strategyCommandRanking(array $orderedIds): array
{
    $ranked = [];
    $rank = 1;

    foreach ($orderedIds as $id) {
        $ranked[$id] = [
            'id' => $id,
            'score' => 1.0,
            'raw_score' => 1.0,
            'score_details' => [],
            'source' => [],
            'rank' => $rank,
        ];
        $rank++;
    }

    return $ranked;
}

/**
 * @param  list<string>  $finalIds
 * @param  array<string, array<string, array{id: string, score: float, raw_score: float, score_details: array<never>, source: array<never>, rank: int}>>  $perStrategy
 */
function strategyCommandResult(array $finalIds, array $perStrategy = []): AdvancedSearchResult
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
        meta: $perStrategy === [] ? [] : ['per_strategy' => $perStrategy],
    );
}

it('fails before evaluation for an unregistered source', function (): void {
    $registry = new ApplicationContentRetrievalProviderRegistry;
    app()->instance(ApplicationContentRetrievalProviderRegistryInterface::class, $registry);

    $this->artisan('ai:evaluate-retrieval-strategies', [
        '--dataset' => '/missing/dataset.json',
        '--source' => 'missing.records',
        '--output' => '/tmp/missing-strategy-report.json',
    ])->assertFailed();
});

it('writes a per-strategy report using a fake engine retriever, with no Elasticsearch involved', function (): void {
    $registry = new ApplicationContentRetrievalProviderRegistry;
    $registry->register(new RetrievalStrategyCommandContentProvider(User::class));
    app()->instance(ApplicationContentRetrievalProviderRegistryInterface::class, $registry);

    $off = strategyCommandResult(
        finalIds: ['cms.strategy_records:1', 'cms.strategy_records:2'],
        perStrategy: [
            'keyword' => strategyCommandRanking(['cms.strategy_records:1', 'cms.strategy_records:2']),
            'vector' => strategyCommandRanking(['cms.strategy_records:1', 'cms.strategy_records:2']),
            'hybrid' => strategyCommandRanking(['cms.strategy_records:1', 'cms.strategy_records:2']),
        ],
    );
    $on = strategyCommandResult(finalIds: ['cms.strategy_records:1', 'cms.strategy_records:2']);
    $retriever = new FakePerStrategyEngineRetriever($off, $on);
    app()->instance(PerStrategyEngineRetrieverInterface::class, $retriever);

    $embedder = Mockery::mock(ITextEmbedder::class);
    $embedder->shouldReceive('embed')->once()->with('private strategy query')->andReturn([0.1, 0.2, 0.3]);
    app()->instance(ITextEmbedder::class, $embedder);

    $directory = sys_get_temp_dir() . '/laraplate-ai-strategy-evaluation-' . bin2hex(random_bytes(5));
    mkdir($directory, 0700, true);
    $dataset_path = $directory . '/dataset.json';
    $output_path = $directory . '/report.json';
    file_put_contents($dataset_path, json_encode([
        'source' => 'cms.strategy_records',
        'data_classification' => 'synthetic',
        'version' => '1',
        'provider_version' => 'fake-v1',
        'corpus_revision' => 'generated-1',
        'cases' => [[
            'id' => 'exact',
            'query' => 'private strategy query',
            'locale' => 'en',
            'limit' => 5,
            'expected_hit_ids' => ['cms.strategy_records:1'],
            'expected_citation_references' => [],
            'expect_authorized_empty' => false,
            'expect_supported_answer' => false,
            'expect_abstention' => false,
            'slices' => [],
            'authorization' => [
                'permission' => 'evaluation.contents.select',
                'filters' => null,
            ],
        ]],
    ], JSON_THROW_ON_ERROR));

    try {
        $this->artisan('ai:evaluate-retrieval-strategies', [
            '--dataset' => $dataset_path,
            '--source' => 'cms.strategy_records',
            '--output' => $output_path,
        ])->assertSuccessful();

        $report = json_decode((string) file_get_contents($output_path), true, flags: JSON_THROW_ON_ERROR);

        expect($report['source'])->toBe('cms.strategy_records')
            ->and($report['case_count'])->toBe(1)
            ->and($report['metrics'])->toHaveKeys(['keyword', 'vector', 'hybrid', 'fused', 'reranked']);

        foreach ($report['metrics'] as $strategy_metrics) {
            expect($strategy_metrics)->toHaveKeys([
                'precision_at_1', 'precision_at_3', 'precision_at_5',
                'recall_at_1', 'recall_at_3', 'recall_at_5',
                'ndcg_at_1', 'ndcg_at_3', 'ndcg_at_5',
            ]);
        }

        // Both calls (useReranker false and true) reused the single embedding computed for the case.
        expect($retriever->calls)->toHaveCount(2)
            ->and($retriever->calls[0]['vector'])->toBe([0.1, 0.2, 0.3])
            ->and($retriever->calls[1]['vector'])->toBe([0.1, 0.2, 0.3])
            ->and($retriever->calls[0]['model'])->toBe(User::class);
    } finally {
        @unlink($output_path);
        @unlink($dataset_path);
        @rmdir($directory);
    }
});
