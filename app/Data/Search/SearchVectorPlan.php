<?php

declare(strict_types=1);

namespace Modules\AI\Data\Search;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * The part of the vector search in the final score. The default is what a missing key means.
 */
final class SearchVectorPlan
{
    #[SchemaProperty(description: 'Weight of the vector score, from 0 to 1.', min: 0, max: 1)]
    public float $weight = 0.2;
}
