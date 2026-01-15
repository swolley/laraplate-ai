<?php

declare(strict_types=1);

namespace Modules\AI\Providers;

use Modules\Core\Overrides\ModuleServiceProvider;

class AIServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'AI';

    protected string $nameLower = 'ai';
}
