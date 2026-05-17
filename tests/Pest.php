<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap-test-fakes.php';

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Unit');
