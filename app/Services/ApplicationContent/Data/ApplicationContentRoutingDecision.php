<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Data;

use InvalidArgumentException;
use Modules\AI\Services\ApplicationContent\Enums\ApplicationContentRoutingStatus;

final readonly class ApplicationContentRoutingDecision
{
    public function __construct(
        public ApplicationContentRoutingStatus $status,
        public ?string $selectedSource = null,
    ) {
        if (($this->status === ApplicationContentRoutingStatus::Selected) !== ($this->selectedSource !== null)) {
            throw new InvalidArgumentException('Application content routing decision is invalid.');
        }
    }
}
