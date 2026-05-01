<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Services\Translation\Definitions\ITranslated;

class TranslatableModelStubTranslation extends Model implements ITranslated
{
    protected $table = 'test_translatable_translations';

    protected $fillable = [
        'translatable_model_stub_id',
        'locale',
        'title',
        'content',
        'components',
    ];

    protected function casts(): array
    {
        return [
            'components' => 'array',
        ];
    }
}
