<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @mixin IdeHelperMessage
 */
final class Message extends Model
{
    use HasFactory;

    #[Override]
    protected $table = 'ai_messages';

    #[Override]
    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'metadata',
        'token_count',
    ];

    /**
     * Get the conversation that owns the message.
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
     * Scope a query to only include messages with a specific role.
     */
    #[Scope]
    protected function byRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }
}
