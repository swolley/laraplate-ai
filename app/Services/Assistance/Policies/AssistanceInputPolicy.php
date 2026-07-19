<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Policies;

use Modules\AI\Exceptions\AssistancePolicyViolationException;
use Modules\AI\Services\Assistance\Contracts\AssistanceSafetyClassifierInterface;
use Throwable;

final readonly class AssistanceInputPolicy
{
    public function __construct(
        private RestrictedTopicPolicy $restricted_topics,
        private AssistanceSafetyClassifierInterface $classifier,
        private int $max_length = 4000,
    ) {}

    public function validate(string $input): string
    {
        $input = trim($input);

        if ($input === '' || mb_strlen($input) > $this->max_length) {
            throw new AssistancePolicyViolationException('input_bounds');
        }

        if ($this->restricted_topics->isRestricted($input)) {
            throw new AssistancePolicyViolationException('restricted_topic');
        }

        try {
            $decision = $this->classifier->classify($input);
        } catch (Throwable) {
            throw new AssistancePolicyViolationException('classifier_unavailable');
        }

        if ($decision !== AssistanceSafetyDecision::Safe) {
            throw new AssistancePolicyViolationException(
                $decision === AssistanceSafetyDecision::Unsafe ? 'prompt_injection' : 'classifier_uncertain',
            );
        }

        return $input;
    }
}
