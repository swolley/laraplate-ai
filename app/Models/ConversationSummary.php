<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AI\Enums\AITables;
use Modules\Core\Overrides\Model;
use Override;

/**
 * @mixin \Eloquent
 * @mixin IdeHelperConversationSummary
 */
final class ConversationSummary extends Model
{
    /**
     * @var string
     */
    #[Override]
    protected $table = AITables::ConversationSummaries->value;

    #[Override]
    protected $fillable = [
        'conversation_id',
        'summary',
        'facts',
        'message_count',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'message_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
