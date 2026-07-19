<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Policies;

use InvalidArgumentException;
use Modules\AI\Enums\AssistantProfile;

final readonly class AssistantPolicyCompiler
{
    public function __construct(
        private AssistantPolicyCatalog $catalog,
    ) {}

    /**
     * @param list<string> $capabilityIds
     * @param list<string> $moduleIds
     */
    public function compile(
        AssistantProfile $profile,
        array $capabilityIds = [],
        array $moduleIds = [],
    ): CompiledAssistantPolicy {
        if ($profile === AssistantProfile::DeveloperHelp && ($capabilityIds !== [] || $moduleIds !== [])) {
            throw new InvalidArgumentException('Developer help cannot receive runtime capability or module policies.');
        }

        $effective = $this->catalog->profile($profile);
        $instructions = [$this->catalog->globalPolicy, $effective->instruction];

        if ($capabilityIds !== []) {
            $capability_sets = [];

            foreach ($capabilityIds as $identifier) {
                $capability_sets[] = $this->catalog->capabilities[$identifier]
                    ?? throw new InvalidArgumentException('Unknown assistant capability policy identifier.');
            }

            $capabilities = AssistantPolicyRuleSet::union($capability_sets);
            $effective = $effective->intersect($capabilities);
            $instructions[] = $capabilities->instruction;
        }

        foreach ($moduleIds as $identifier) {
            $module = $this->catalog->modules[$identifier]
                ?? throw new InvalidArgumentException('Unknown assistant module policy identifier.');
            $effective = $effective->intersect($module);
            $instructions[] = $module->instruction;
        }

        return new CompiledAssistantPolicy(
            version: $this->catalog->version,
            systemPrompt: implode("\n\n", $instructions),
            allowedCorpora: $effective->allowedCorpora,
            allowedTools: $effective->allowedTools,
            allowedFields: $effective->allowedFields,
        );
    }
}
