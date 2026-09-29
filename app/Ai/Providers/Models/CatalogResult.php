<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers\Models;

use Modules\AI\Enums\ProviderListingStatus;

final readonly class CatalogResult
{
    /**
     * @param  array<string, list<string>>  $choices  keyed by setting name
     * @param  array<string, ProviderOutcome>  $outcomes  keyed by provider
     */
    public function __construct(
        public array $choices,
        public array $outcomes,
    ) {}

    public function hasFailures(): bool
    {
        return array_any($this->outcomes, static fn (ProviderOutcome $outcome): bool => $outcome->status === ProviderListingStatus::Failed);
    }
}
