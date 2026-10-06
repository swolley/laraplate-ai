<?php

declare(strict_types=1);

namespace Modules\AI\Data\Search;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * When a search that scored badly is run again. The defaults are what a missing key means.
 */
final class SearchRetryPolicy
{
    #[SchemaProperty(description: 'Whether a search with a low average score is run again.')]
    public bool $enabled = true;

    #[SchemaProperty(description: 'How many attempts in all (1 to 3).', min: 1, max: 3)]
    public int $max_attempts = 2;

    #[SchemaProperty(description: 'The average score below which the search is run again.')]
    public float $threshold_avg_score = 1.5;
}
