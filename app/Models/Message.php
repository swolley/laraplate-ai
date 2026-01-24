<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperMessage
 */
final class Message extends Model
{
    use HasFactory;

    protected $table = 'ai_messages';

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'metadata',
        'token_count',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'token_count' => 'integer',
        ];
    }

    /**
     * Get the conversation that owns the message.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Scope a query to only include messages with a specific role.
     */
    public function scopeByRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }
}
