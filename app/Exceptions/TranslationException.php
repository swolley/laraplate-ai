<?php

declare(strict_types=1);

namespace Modules\AI\Exceptions;

use RuntimeException;
use Throwable;

final class TranslationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
