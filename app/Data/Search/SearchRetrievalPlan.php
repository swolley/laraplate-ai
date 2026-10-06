<?php

declare(strict_types=1);

namespace Modules\AI\Data\Search;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * Which retrievals run for a query. The defaults are what a missing key means.
 */
final class SearchRetrievalPlan
{
    #[SchemaProperty(description: 'Whether the full text search runs.')]
    public bool $use_fulltext = true;

    #[SchemaProperty(description: 'Whether the vector search runs.')]
    public bool $use_vector = true;

    #[SchemaProperty(description: 'Whether the results of the searches are merged by the ensemble.')]
    public bool $use_ensemble = true;

    #[SchemaProperty(description: 'How many candidates each search returns (10 to 200).', min: 10, max: 200)]
    public int $size = 50;
}
