<?php

declare(strict_types=1);

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AI\Models\WriteProposal;
use Modules\AI\Services\Assistance\Writes\ActingUserName;
use Modules\Core\Models\User;
use Override;

/**
 * What a client shows of a write the assistant proposed: what would change, as whom, and where it stands.
 *
 * @property WriteProposal $resource
 */
final class WriteProposalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $proposal = $this->resource;

        return [
            'id' => $proposal->getKey(),
            'tool' => $proposal->tool,
            'module' => $proposal->module,
            'entity' => $proposal->entity,
            'operation' => $proposal->operation,
            'status' => $proposal->status->value,
            'acting_user_id' => $proposal->user_id,
            'acting_user_name' => $proposal->user instanceof User ? ActingUserName::of($proposal->user) : null,
            'summary' => $proposal->summary,
            'requires_approval' => $proposal->requires_approval,
            'outcome' => $proposal->outcome,
            'expires_at' => $proposal->expires_at->toIso8601String(),
            'resolved_at' => $proposal->resolved_at?->toIso8601String(),
        ];
    }
}
