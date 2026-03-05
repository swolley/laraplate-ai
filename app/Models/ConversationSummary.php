<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @mixin IdeHelperConversationSummary
 */
final class ConversationSummary extends Model
{
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
    use \Illuminate\Database\Eloquent\Factories\HasFactory;

    #[Override]
    public $timestamps = false;

    #[Override]
    protected $table = 'ai_conversation_summaries';

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
