<?php

declare(strict_types=1);

namespace Stubs;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Helpers\HasTranslations;
use Override;

class TranslatableMissingTestModel extends Model
{
    use HasTranslations;
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    #[Override]
    protected $table = 'test_translatable_missing';

    public function getTable(): string
    {
        return 'test_translatable_missing';
    }
}

class TranslatableMissingTestModelA extends TranslatableMissingTestModel
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    #[Override]
    protected $table = 'test_missing_a';
}

class TranslatableMissingTestModelB extends TranslatableMissingTestModel
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    #[Override]
    protected $table = 'test_missing_b';
}
