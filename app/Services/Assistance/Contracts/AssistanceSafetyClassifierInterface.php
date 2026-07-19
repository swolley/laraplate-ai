<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Contracts;

use Modules\AI\Services\Assistance\Policies\AssistanceSafetyDecision;

interface AssistanceSafetyClassifierInterface
{
    public function classify(string $input): AssistanceSafetyDecision;
}
