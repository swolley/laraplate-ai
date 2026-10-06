<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Translation;

use Modules\AI\Contracts\ITranslatableModelClassNames;
use Override;

/**
 * The translatable model classes that a command is to see, as the test lists them.
 */
final readonly class FixedTranslatableModelClassNames implements ITranslatableModelClassNames
{
    /**
     * @param  list<class-string>  $model_list
     */
    public function __construct(private array $model_list) {}

    /**
     * @return list<class-string>
     */
    #[Override]
    public function all(): array
    {
        return $this->model_list;
    }
}
