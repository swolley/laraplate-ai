<?php

declare(strict_types=1);

namespace Modules\AI\Data\Search;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * The filters a search query asks for: for now only a date range, which is null unless the query
 * mentions dates.
 */
final class SearchFilters
{
    #[SchemaProperty(description: 'The dates the query asks for. Null unless the query explicitly mentions dates.')]
    public ?SearchDateRange $date_range = null;
}
