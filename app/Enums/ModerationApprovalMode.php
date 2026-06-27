<?php

declare(strict_types=1);

namespace Modules\AI\Enums;

use function ai_config_string;

enum ModerationApprovalMode: string
{
    case Threshold = 'threshold';
    case Dual = 'dual';

    public static function fromConfig(): self
    {
        $raw = ai_config_string('ai.features.moderation.approval_mode', 'threshold');

        return self::tryFrom($raw) ?? self::Threshold;
    }
}
