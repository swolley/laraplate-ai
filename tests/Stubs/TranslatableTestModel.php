<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\HasTranslations;
use Override;

class TranslatableTestModel extends Model
{
    use HasFactory;
    use HasTranslations;

    #[Override]
    protected $table = 'test_translatable_models';

    public function getTable(): string
    {
        return 'test_translatable_models';
    }
}
