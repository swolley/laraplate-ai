<?php

declare(strict_types=1);

use DG\BypassFinals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Tests\TestCase;

BypassFinals::enable();

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');
