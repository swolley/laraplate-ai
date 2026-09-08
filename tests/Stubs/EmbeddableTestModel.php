<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Search\Traits\Searchable;
use Override;

/**
 * Minimal searchable + embeddable model for exercising the embeddings repair
 * command against a real table without depending on a production model.
 */
class EmbeddableTestModel extends Model
{
    use Searchable;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected array $embed = ['title'];

    #[Override]
    protected $table = 'embeddable_test_models';

    #[Override]
    public function getTable(): string
    {
        return 'embeddable_test_models';
    }
}
