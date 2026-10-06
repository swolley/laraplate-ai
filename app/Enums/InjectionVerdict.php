<?php

declare(strict_types=1);

namespace Modules\AI\Enums;

/**
 * What the model classifier answers about a user message.
 */
enum InjectionVerdict: string
{
    case Safe = 'safe';
    case Unsafe = 'unsafe';
}
