<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Data;

use InvalidArgumentException;

/**
 * Server-verified application location. Client payloads must never instantiate this DTO directly.
 */
final readonly class ApplicationContentRequestContext
{
    public string $module;

    public ?string $entity;

    public function __construct(
        string $module,
        ?string $entity = null,
        public int|string|null $recordKey = null,
    ) {
        $this->module = self::identifier($module);
        $this->entity = $entity === null ? null : self::identifier($entity);

        if (is_string($this->recordKey)
            && (mb_trim($this->recordKey) === '' || mb_strlen($this->recordKey) > 255)) {
            throw new InvalidArgumentException('Application content request context is invalid.');
        }
    }

    private static function identifier(string $value): string
    {
        $value = mb_strtolower(mb_trim($value));

        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $value) !== 1) {
            throw new InvalidArgumentException('Application content request context identifier is invalid.');
        }

        return $value;
    }
}
