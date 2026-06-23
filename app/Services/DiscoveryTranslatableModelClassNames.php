<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Modules\AI\Contracts\ITranslatableModelClassNames;
use Modules\Core\Models\Concerns\HasTranslations;
use Override;

final class DiscoveryTranslatableModelClassNames implements ITranslatableModelClassNames
{
    /**
     * @return list<class-string>
     */
    #[Override]
    public function all(): array
    {
        return models(
            true,
            filter: fn (string $model): bool => class_uses_trait($model, HasTranslations::class),
        );
    }
}
