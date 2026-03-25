<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Unit;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Search\Traits\Searchable;

class SearchableModelStub extends Model
{
    use HasFactory;
    use Searchable;

    public array $embed = ['title'];

    public function getTable(): string
    {
        return 'test_searchable';
    }

    public function vectorSearchEnabled(): bool
    {
        return true;
    }

    public function prepareDataToEmbed(): ?string
    {
        return null;
    }

    public function embeddings(): HasMany
    {
        return $this->hasMany(Model::class);
    }
}
