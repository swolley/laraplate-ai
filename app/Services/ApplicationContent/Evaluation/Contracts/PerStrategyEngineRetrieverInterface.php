<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Evaluation\Contracts;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Search\DTOs\AdvancedSearchResult;

/**
 * Injectable seam between {@see \Modules\AI\Console\EvaluateApplicationContentRetrievalStrategiesCommand}
 * and the real search engine. The command resolves this interface, not
 * {@see \Modules\Core\Search\Services\EnsembleSearchService} directly, so the
 * feature test can bind a fake that returns canned
 * {@see AdvancedSearchResult}s (with a `meta['per_strategy']` breakdown) and
 * run the full per-strategy evaluation without Elasticsearch. The command
 * embeds the case query once (via {@see \Modules\Core\Search\Contracts\ITextEmbedder})
 * and passes the resulting vector in, so the interface itself carries no
 * embedding concern.
 */
interface PerStrategyEngineRetrieverInterface
{
    /**
     * Run one ensemble search for a case query.
     *
     * @param  list<float>|null  $vector  Pre-computed query embedding, or null when no vector is available.
     */
    public function retrieve(Model $model, string $query, bool $useReranker, int $limit, ?array $vector): AdvancedSearchResult;
}
