<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\User;

/**
 * @mixin IdeHelperConversation
 */
final class Conversation extends Model
{
    use HasFactory;

    protected $table = 'ai_conversations';

    protected $fillable = [
        'user_id',
        'title',
        'system_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * Get the user that owns the conversation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the messages for the conversation.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    /**
     * Add a message to the conversation.
     */
    public function addMessage(string $role, string $content, ?array $metadata = null): Message
    {
        return $this->messages()->create([
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get messages formatted for LLPhant (Message[] format).
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function getMessagesForLLPhant(): array
    {
        $messages = [];

        // Add system message if present
        if ($this->system_message) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->system_message,
            ];
        }

        // Add conversation messages
        foreach ($this->messages as $message) {
            $messages[] = [
                'role' => $message->role,
                'content' => $message->content,
            ];
        }

        return $messages;
    }
}
