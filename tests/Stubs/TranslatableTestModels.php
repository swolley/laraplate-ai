<?php

declare(strict_types=1);

namespace Stubs;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Helpers\HasTranslations;
use Override;

class TranslatableTestModel extends Model
{
    use HasTranslations;
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    #[Override]
    protected $table = 'test_translatable_models';

    public function getTable(): string
    {
        return 'test_translatable_models';
    }
}

class TranslatableTestModelA extends TranslatableTestModel
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    #[Override]
    protected $table = 'test_translatable_a';
}

class TranslatableTestModelB extends TranslatableTestModel
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    #[Override]
    protected $table = 'test_translatable_b';
}
