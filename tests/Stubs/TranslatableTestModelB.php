<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Override;

class TranslatableTestModelB extends TranslatableTestModel
{
    use HasFactory;

    #[Override]
    protected $table = 'test_translatable_b';
}
