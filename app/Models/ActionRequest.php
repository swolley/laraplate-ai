<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Model;
use Override;

/**
 * @mixin IdeHelperActionRequest
 */
final class ActionRequest extends Model
{
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

    private bool $softDeletesEnabled = false;

    private bool $versionStrategy = false;

    public function getRules(): array
    {
        $rules = parent::getRules();
        $rules[Model::DEFAULT_RULE] = array_merge($rules[Model::DEFAULT_RULE], [
            'conversation_id' => ['required', 'exists:ai_conversations,id'],
            'status' => ['required', 'string', 'max:255'],
        ]);
        $rules['create'] = array_merge($rules['create'], [
            'user_id' => ['required', 'exists:users,id'],
            'tool_name' => ['required', 'string', 'max:255'],
            'tool_args' => ['required', 'array'],
            'risk_level' => ['required', 'string', 'max:255'],
        ]);

        return $rules;
    }

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

    #[Scope]
    protected function pendingUserConfirmation(Builder $query): Builder
    {
        return $query->where('status', 'pending_user_confirmation');
    }

    #[Scope]
    protected function pendingAdminApproval(Builder $query): Builder
    {
        return $query->where('status', 'pending_admin_approval');
    }

    #[Scope]
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
