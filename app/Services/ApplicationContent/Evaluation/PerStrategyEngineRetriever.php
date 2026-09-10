<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Evaluation;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Services\ApplicationContent\Evaluation\Contracts\PerStrategyEngineRetrieverInterface;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\Services\EnsembleSearchService;
use Override;

/**
 * Real {@see PerStrategyEngineRetrieverInterface} implementation: runs one
 * ensemble search (keyword + vector strategies, RRF fusion, optional
 * reranking) through {@see EnsembleSearchService} and returns its result,
 * whose `meta['per_strategy']` map and {@see AdvancedSearchResult::ids()}
 * ordering the retrieval strategy evaluation service reads.
 */
final readonly class PerStrategyEngineRetriever implements PerStrategyEngineRetrieverInterface
{
    public function __construct(private EnsembleSearchService $ensemble) {}

    #[Override]
    public function retrieve(Model $model, string $query, bool $useReranker, int $limit, ?array $vector): AdvancedSearchResult
    {
        $plan = [
            'retrieval' => ['use_fulltext' => true, 'use_vector' => true],
            'ensemble' => [],
            'ranking' => ['use_reranker' => $useReranker],
        ];

        return $this->ensemble->search($model, $query, $plan, $vector, 1, $limit);
    }
}
