<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance;

use function ai_config_int;

use Closure;
use Modules\AI\Services\Assistance\Policies\AssistanceContextPolicy;
use Modules\AI\Services\Assistance\Policies\AssistanceInputPolicy;
use Modules\AI\Services\Assistance\Policies\AssistanceOutputPolicy;
use Modules\AI\Services\Assistance\Policies\CallableAssistanceSafetyClassifier;
use Modules\AI\Services\Assistance\Policies\DeterministicAssistanceSafetyClassifier;
use Modules\AI\Services\Assistance\Policies\RestrictedTopicPolicy;

final readonly class AssistanceGuardrailPipeline
{
    public function __construct(
        private AssistanceInputPolicy $input_policy,
        private AssistanceContextPolicy $context_policy,
        private AssistanceOutputPolicy $output_policy,
    ) {}

    public static function defaults(?Closure $classifier = null): self
    {
        $restricted_topics = new RestrictedTopicPolicy;
        $safety_classifier = $classifier !== null
            ? new CallableAssistanceSafetyClassifier($classifier)
            : new DeterministicAssistanceSafetyClassifier;

        return new self(
            input_policy: new AssistanceInputPolicy(
                $restricted_topics,
                $safety_classifier,
                ai_config_int('ai.features.guardrails.in_app_max_input_length', 4000),
            ),
            context_policy: new AssistanceContextPolicy($safety_classifier),
            output_policy: new AssistanceOutputPolicy(
                $restricted_topics,
                ai_config_int('ai.features.guardrails.in_app_max_output_length', 8000),
            ),
        );
    }

    public function validateInput(string $input): string
    {
        return $this->input_policy->validate($input);
    }

    public function validateContext(AssistantPromptContext $context): AssistantPromptContext
    {
        return $this->context_policy->validate($context);
    }

    public function validateOutput(string $output): string
    {
        return $this->output_policy->validate($output);
    }
}
