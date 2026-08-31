<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Scope;

use InvalidArgumentException;

final readonly class AssistantScope
{
    public const string MODULE_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public function __construct(
        public ?string $moduleKey,
        public DataAccess $dataAccess,
        public DocScope $docScope,
    ) {
        if ($this->moduleKey !== null && preg_match(self::MODULE_KEY_PATTERN, $this->moduleKey) !== 1) {
            throw new InvalidArgumentException('Assistant scope module key is invalid.');
        }

        if ($this->moduleKey === null
            && ($this->docScope === DocScope::Module || $this->dataAccess === DataAccess::Module)) {
            throw new InvalidArgumentException('Module-scoped assistance requires a module key.');
        }
    }

    public static function generic(): self
    {
        return new self(null, DataAccess::None, DocScope::Application);
    }
}
