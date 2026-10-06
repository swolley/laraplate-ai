<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

/**
 * A model that has a table name and nothing else.
 */
final class PlainTestModel extends Model
{
    protected $table = 'test';
}
