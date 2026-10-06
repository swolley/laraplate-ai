<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Services\Translation\Definitions\ITranslated;
use Override;

class TranslatableMissingTestModelTranslation extends Model implements ITranslated
{
    #[Override]
    protected $table = 'translation_stub';

    #[Override]
    protected $fillable = [
        'translatable_missing_test_model_id',
        'locale',
    ];
}
