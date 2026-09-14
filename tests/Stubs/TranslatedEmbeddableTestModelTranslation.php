<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Services\Translation\Definitions\ITranslated;
use Override;

class TranslatedEmbeddableTestModelTranslation extends Model implements ITranslated
{
    public $timestamps = false;

    #[Override]
    protected $table = 'translated_embeddable_test_model_translations';

    #[Override]
    protected $fillable = [
        'translated_embeddable_test_model_id',
        'locale',
        'title',
    ];
}
