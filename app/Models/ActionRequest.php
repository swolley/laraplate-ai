<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;
use Override;

/**
 * @mixin IdeHelperActionRequest
 */
final class ActionRequest extends Model
{
    use HasFactory;

    #[Override]
    protected $table = 'ai_action_requests';

    #[Override]
    protected $fillable = [
        'conversation_id',
        'user_id',
        'tool_name',
        'tool_args',
        'risk_level',
        'status',
        'modification_id',
        'result',
        'error',
        'executed_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function requiresApproval(): bool
    {
        return $this->risk_level === 'high';
    }

    public function requiresUserConfirmation(): bool
    {
        return $this->risk_level === 'medium';
    }

    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function pendingUserConfirmation(Builder $query): Builder
    {
        return $query->where('status', 'pending_user_confirmation');
    }

    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function pendingAdminApproval(Builder $query): Builder
    {
        return $query->where('status', 'pending_admin_approval');
    }

    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function forUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    protected function casts(): array
    {
        return [
            'tool_args' => 'array',
            'result' => 'array',
            'executed_at' => 'datetime',
        ];
    }
}
