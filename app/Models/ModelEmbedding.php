<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @mixin IdeHelperModelEmbedding
 */
final class ModelEmbedding extends Model
{
    use HasFactory;

    protected $connection {
        get {
            return config('search.embedding_model_connection');
        }
        set {
            $this->connection = $value;
        }
    }

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'embedding',
    ];

    /**
     * The model that belongs to the embedding.
     *
     * @return MorphTo<Model>
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return [
            'embedding' => 'json',
        ];
    }
}
