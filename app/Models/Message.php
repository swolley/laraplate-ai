<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AI\Enums\AITables;
use Modules\Core\Overrides\Model;
use Override;

/**
 * @property int|null $id
 * @property int $conversation_id
 * @property string $role
 * @property string $content
 * @property array<string, mixed>|null $metadata
 * @property int|null $token_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Eloquent
 * @mixin IdeHelperMessage
 */
final class Message extends Model
{
    /**
     * @var string
     */
    #[Override]
    protected $table = AITables::Messages->value;

    #[Override]
    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'metadata',
        'token_count',
    ];

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'token_count' => 'integer',
        ];
    }

    /**
     * @param  Builder<Message>  $query
     * @return Builder<Message>
     */
    #[Scope]
    protected function byRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }
}
