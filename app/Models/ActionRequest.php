<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AI\Enums\AITables;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Model;
use Override;

/**
 * @mixin \Eloquent
 * @mixin IdeHelperActionRequest
 */
final class ActionRequest extends Model
{
    /**
     * @var string
     */
    #[Override]
    protected $table = AITables::ActionRequests->value;

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

    public function getRules(): array
    {
        $rules = parent::getRules();
        $rules[Model::DEFAULT_RULE] = array_merge($rules[Model::DEFAULT_RULE], [
            'conversation_id' => ['nullable', 'exists:' . AITables::Conversations->value . ',id'],
            'status' => ['required', 'string', 'max:255'],
        ]);
        $rules['create'] = array_merge($rules['create'], [
            'user_id' => ['required', 'exists:' . CoreTables::Users->value . ',id'],
            'tool_name' => ['required', 'string', 'max:255'],
            // Validated from raw attributes: array-cast columns are JSON strings before insert.
            'tool_args' => ['required', 'json'],
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
