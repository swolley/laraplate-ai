<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Modules\AI\Tests\Unit\SearchableModelStub;
use Override;

/**
 * A searchable model whose key is not a scalar, for the code that caches by key.
 */
final class CompoundKeySearchableModel extends SearchableModelStub
{
    #[Override]
    public function getKey(): mixed
    {
        return ['compound'];
    }
}
