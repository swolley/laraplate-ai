<?php

declare(strict_types=1);

namespace Modules\AI\Data\Search;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * How the merged results are ranked. The defaults are what a missing key means.
 */
final class SearchRankingPlan
{
    #[SchemaProperty(description: 'Whether a reranker reorders the best candidates.')]
    public bool $use_reranker = true;

    #[SchemaProperty(description: 'How many candidates the reranker sees (5 to 100).', min: 5, max: 100)]
    public int $rerank_top_k = 30;
}
