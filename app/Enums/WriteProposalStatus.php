<?php

declare(strict_types=1);

namespace Modules\AI\Enums;

/**
 * Lifecycle of a write the assistant proposed. A proposal is created `Proposed` and changes nothing.
 * The person confirms it (`Applying`, then `Applied` or `PendingApproval` when Core captured the write
 * for a vote, or `Failed`) or rejects it; one nobody confirms in time becomes `Expired`.
 */
enum WriteProposalStatus: string
{
    case Proposed = 'proposed';
    case Applying = 'applying';
    case Applied = 'applied';
    case PendingApproval = 'pending_approval';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Failed = 'failed';

    public function isOpen(): bool
    {
        return $this === self::Proposed;
    }
}
