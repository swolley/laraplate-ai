<?php

declare(strict_types=1);

namespace Modules\AI\Enums;

enum AssistantTenantScope: string
{
    case Global = 'global';
    case Tenant = 'tenant';
}
