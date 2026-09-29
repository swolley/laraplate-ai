<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers\Models;

use Modules\AI\Enums\ProviderListingStatus;

final readonly class ProviderOutcome
{
    public function __construct(
        public string $provider,
        public ProviderListingStatus $status,
        public int $modelCount = 0,
        public ?string $error = null,
    ) {}
}
