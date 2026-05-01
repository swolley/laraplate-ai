<?php

declare(strict_types=1);

/**
 * Test-only models under the Stubs namespace and helpers. Do not load shims that
 * replace Modules\Core classes (those break the real application bootstrap).
 */
require_once __DIR__ . '/Stubs/helpers.php';

require_once __DIR__ . '/Stubs/TranslatableTestModels.php';

require_once __DIR__ . '/Stubs/TranslatableMissingTestModels.php';

require_once __DIR__ . '/Stubs/TranslateModelJobStub.php';
