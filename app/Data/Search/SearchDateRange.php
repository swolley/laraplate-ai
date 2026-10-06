<?php

declare(strict_types=1);

namespace Modules\AI\Data\Search;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * A date range the query asks for, as ISO dates.
 */
final class SearchDateRange
{
    #[SchemaProperty(description: 'First day of the range, as an ISO date (YYYY-MM-DD), or null when the query gives none.')]
    public ?string $from = null;

    #[SchemaProperty(description: 'Last day of the range, as an ISO date (YYYY-MM-DD), or null when the query gives none.')]
    public ?string $to = null;

    /**
     * @return array<string, string>|null null when the query names no date
     */
    public function toArray(): ?array
    {
        $range = array_filter(['from' => $this->from, 'to' => $this->to], static fn (?string $date): bool => $date !== null && $date !== '');

        return $range === [] ? null : $range;
    }
}
