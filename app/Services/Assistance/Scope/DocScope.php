<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Scope;

enum DocScope: string
{
    case Module = 'module';
    case Application = 'application';
}
