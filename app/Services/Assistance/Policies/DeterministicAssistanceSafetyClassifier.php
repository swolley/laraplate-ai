<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Policies;

use Modules\AI\Services\Assistance\Contracts\AssistanceSafetyClassifierInterface;

final class DeterministicAssistanceSafetyClassifier implements AssistanceSafetyClassifierInterface
{
    public function classify(string $input): AssistanceSafetyDecision
    {
        if (preg_match('/\b(ignore|disregard|override|bypass)\b.{0,80}\b(previous|prior|system|instruction|policy|safety)\b/iu', $input) === 1
            || preg_match('/\b(reveal|show|print|repeat|extract)\b.{0,50}\b(system prompt|instructions?|policy|guardrails?)\b/iu', $input) === 1) {
            return AssistanceSafetyDecision::Unsafe;
        }

        return AssistanceSafetyDecision::Safe;
    }
}
