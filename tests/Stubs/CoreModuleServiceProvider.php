<?php

declare(strict_types=1);

namespace Modules\Core\Overrides;

if (! class_exists(ModuleServiceProvider::class, false)) {
    abstract class ModuleServiceProvider extends \Illuminate\Support\ServiceProvider
    {
        protected string $name = '';

        protected string $nameLower = '';
    }
}
