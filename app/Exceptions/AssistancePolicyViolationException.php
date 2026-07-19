<?php

declare(strict_types=1);

namespace Modules\AI\Exceptions;

use RuntimeException;

final class AssistancePolicyViolationException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
    ) {
        parent::__construct('The requested in-app assistance cannot be provided.');
    }
}
