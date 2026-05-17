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
