<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Scope;

enum DataAccess: string
{
    case None = 'none';
    case Module = 'module';
    case Application = 'application';
}
