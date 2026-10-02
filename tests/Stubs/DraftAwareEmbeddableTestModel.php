<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Override;

/**
 * Embeddable model that, like Media with its drafts, decides for itself that some rows never
 * reach the search index: a title starting with "Draft" is not searchable.
 */
class DraftAwareEmbeddableTestModel extends EmbeddableTestModel
{
    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! str_starts_with((string) $this->title, 'Draft');
    }
}
