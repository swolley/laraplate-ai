<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use Modules\AI\Data\UiProposal;
use Modules\AI\Services\Assistance\Proposals\UiProposalCollector;

/**
 * Lets the model suggest a new value for a preference the page declared proposable. It changes
 * nothing: the proposal waits in the message for the user to accept or refuse it.
 */
final readonly class ProposePreferenceChangeTool
{
    public const string NAME = 'propose_preference_change';

    public function __construct(private UiProposalCollector $collector) {}

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: ProposalToolText::description(
                'Suggest a new value for one user preference. The user accepts or refuses it; nothing changes until they accept.',
                $this->collector->targets()->describe(UiProposal::KIND_PREFERENCE),
            ),
            parameters: [
                ['name' => 'namespace', 'type' => 'string', 'description' => 'Namespace of the preference, from the list of proposable targets.', 'maxLength' => 40],
                ['name' => 'key', 'type' => 'string', 'description' => 'Key of the preference, from the list of proposable targets.', 'maxLength' => 64],
                ['name' => 'proposed', 'type' => 'string', 'description' => ProposalToolText::PROPOSED_DESCRIPTION, 'maxLength' => 2000],
                ['name' => 'reason', 'type' => 'string', 'description' => ProposalToolText::REASON_DESCRIPTION, 'maxLength' => UiProposalCollector::MAX_REASON_LENGTH],
            ],
            riskLevel: 'low',
            handler: fn (mixed $namespace, mixed $key, mixed $proposed, mixed $reason): string => $this->collector->proposePreference($namespace, $key, $proposed, $reason),
        );
    }
}
