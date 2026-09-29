<?php

declare(strict_types=1);

namespace Modules\AI\Enums;

/**
 * What a listed model can do, as far as feature selection cares.
 */
enum ModelCapability: string
{
    case Chat = 'chat';
    case Tools = 'tools';
    case Vision = 'vision';
}
