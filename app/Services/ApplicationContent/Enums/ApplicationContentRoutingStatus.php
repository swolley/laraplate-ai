<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Enums;

enum ApplicationContentRoutingStatus: string
{
    case Selected = 'selected';
    case NoMatch = 'no_match';
    case ClarificationRequired = 'clarification_required';
}
