<?php

declare(strict_types=1);

namespace Modules\AI\Enums;

enum AssistantProfile: string
{
    case DeveloperHelp = 'developer_help';
    case InAppAssistance = 'in_app_assistance';
}
