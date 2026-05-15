<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AI\Enums\AITables;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Model;
use Override;

/**
 * @mixin \Eloquent
 * @mixin IdeHelperContextualSuggestion
 */
final class ContextualSuggestion extends Model
{
    #[Override]
    protected $table = AITables::ContextualSuggestions->value;

    #[Override]
    protected $fillable = [
        'user_id',
        'context',
        'suggestion',
        'dismissed_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dismiss(): void
    {
        $this->update(['dismissed_at' => now()]);
    }

    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function forUser(Builder $query, int $user_id): Builder
    {
        return $query->where('user_id', $user_id);
    }

    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function notDismissed(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    #[\Illuminate\Database\Eloquent\Attributes\Scope]
    protected function recent(Builder $query, int $minutes = 60): Builder
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'dismissed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
