<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Services\Translation\Definitions\ITranslated;
use Override;

class TranslatableTestModelTranslation extends Model implements ITranslated
{
    #[Override]
    protected $table = 'test_translatable_model_translations';

    #[Override]
    protected $fillable = [
        'translatable_test_model_id',
        'locale',
        'title',
        'content',
    ];
}
