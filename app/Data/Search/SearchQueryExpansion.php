<?php

declare(strict_types=1);

namespace Modules\AI\Data\Search;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * The query rewritten for a better search.
 */
final class SearchQueryExpansion
{
    #[SchemaProperty(description: 'An expanded or reformulated version of the query for better search results.')]
    public string $must = '';
}
