<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\ApplicationContent;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Services\ApplicationContent\Evaluation\Contracts\PerStrategyEngineRetrieverInterface;
use Modules\Core\Search\DTOs\AdvancedSearchResult;

/**
 * Fake {@see PerStrategyEngineRetrieverInterface} for feature tests: returns
 * one canned {@see AdvancedSearchResult} for `useReranker = false` (read for
 * the per-strategy breakdown and the fused ordering) and another for
 * `useReranker = true` (read for the reranked ordering), so the command's
 * feature test needs no Elasticsearch.
 */
final class FakePerStrategyEngineRetriever implements PerStrategyEngineRetrieverInterface
{
    /**
     * @var list<array{model: class-string<Model>, query: string, useReranker: bool, limit: int, vector: ?list<float>}>
     */
    public array $calls = [];

    public function __construct(
        private readonly AdvancedSearchResult $off,
        private readonly AdvancedSearchResult $on,
    ) {}

    public function retrieve(Model $model, string $query, bool $useReranker, int $limit, ?array $vector): AdvancedSearchResult
    {
        $this->calls[] = [
            'model' => $model::class,
            'query' => $query,
            'useReranker' => $useReranker,
            'limit' => $limit,
            'vector' => $vector,
        ];

        return $useReranker ? $this->on : $this->off;
    }
}
