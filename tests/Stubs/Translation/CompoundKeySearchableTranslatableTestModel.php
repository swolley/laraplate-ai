<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Translation;

use Override;

/**
 * The same model with a key that is not a scalar, for the code that caches by key.
 */
final class CompoundKeySearchableTranslatableTestModel extends SearchableTranslatableTestModel
{
    #[Override]
    public function getKey(): mixed
    {
        return ['compound'];
    }
}
