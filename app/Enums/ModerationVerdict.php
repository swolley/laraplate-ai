<?php

declare(strict_types=1);

namespace Modules\AI\Enums;

enum ModerationVerdict: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Uncertain = 'uncertain';

    public static function tryFromString(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Uncertain;
    }
}
