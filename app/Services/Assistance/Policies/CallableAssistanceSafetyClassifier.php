<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Policies;

use Closure;
use Modules\AI\Services\Assistance\Contracts\AssistanceSafetyClassifierInterface;

final readonly class CallableAssistanceSafetyClassifier implements AssistanceSafetyClassifierInterface
{
    public function __construct(
        private Closure $classifier,
    ) {}

    public function classify(string $input): AssistanceSafetyDecision
    {
        $result = ($this->classifier)($input);

        return is_string($result)
            ? (AssistanceSafetyDecision::tryFrom($result) ?? AssistanceSafetyDecision::Uncertain)
            : AssistanceSafetyDecision::Uncertain;
    }
}
