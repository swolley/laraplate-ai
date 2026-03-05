<?php

declare(strict_types=1);

namespace Modules\AI\Providers;

use Modules\Core\Overrides\ModuleServiceProvider;
use Override;

class AIServiceProvider extends ModuleServiceProvider
{
    #[Override]
    protected string $name = 'AI';

    #[Override]
    protected string $nameLower = 'ai';
}
