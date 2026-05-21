<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-test-fakes.php';

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__DIR__ . '/Integration', __DIR__ . '/Feature');
