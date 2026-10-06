<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use Modules\AI\Data\UiProposal;
use Modules\AI\Services\Assistance\Proposals\UiProposalCollector;

/**
 * Lets the model suggest a new state for a view the page declared proposable, such as the filters
 * of a list. It changes nothing: the proposal waits in the message for the user to accept or refuse it.
 */
final readonly class ProposeViewStateTool
{
    public const string NAME = 'propose_view_state';

    public function __construct(private UiProposalCollector $collector) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: ProposalToolText::description(
                'Suggest a new state for one view of the page, such as its filters or its layout. The user accepts or refuses it; nothing changes until they accept.',
                $this->collector->targets()->describe(UiProposal::KIND_VIEW_STATE),
            ),
            parameters: [
                ['name' => 'resource', 'type' => 'string', 'description' => 'Resource of the view, as module/entity, from the list of proposable targets.', 'maxLength' => 129],
                ['name' => 'view', 'type' => 'string', 'description' => 'Name of the view, from the list of proposable targets.', 'maxLength' => 64],
                ['name' => 'proposed', 'type' => 'string', 'description' => ProposalToolText::PROPOSED_DESCRIPTION, 'maxLength' => 2000],
                ['name' => 'reason', 'type' => 'string', 'description' => ProposalToolText::REASON_DESCRIPTION, 'maxLength' => UiProposalCollector::MAX_REASON_LENGTH],
            ],
            riskLevel: 'low',
            handler: fn (mixed $resource, mixed $view, mixed $proposed, mixed $reason): string => $this->collector->proposeViewState($resource, $view, $proposed, $reason),
        );
    }
}
