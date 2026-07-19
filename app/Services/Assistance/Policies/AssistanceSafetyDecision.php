<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Policies;

enum AssistanceSafetyDecision: string
{
    case Safe = 'safe';
    case Unsafe = 'unsafe';
    case Uncertain = 'uncertain';
}
