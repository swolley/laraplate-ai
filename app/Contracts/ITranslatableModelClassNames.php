<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

interface ITranslatableModelClassNames
{
    /**
     * @return list<class-string>
     */
    public function all(): array;
}
