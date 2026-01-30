<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;

final class ContextualSuggestion extends Model
{
    public $timestamps = false;

    protected $table = 'ai_contextual_suggestions';

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

    public function scopeForUser(Builder $query, int $user_id): Builder
    {
        return $query->where('user_id', $user_id);
    }

    public function scopeNotDismissed(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    public function scopeRecent(Builder $query, int $minutes = 60): Builder
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }

    public function dismiss(): void
    {
        $this->update(['dismissed_at' => now()]);
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
