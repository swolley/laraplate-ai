<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Writes;

use Modules\AI\Enums\WriteProposalStatus;

/**
 * What applying a stored write proposal came to: the final status and the outcome the person is shown.
 */
final readonly class WriteApplyResult
{
    /**
     * @param  array<string, mixed>  $outcome
     */
    public function __construct(
        public WriteProposalStatus $status,
        public array $outcome,
    ) {}
}
