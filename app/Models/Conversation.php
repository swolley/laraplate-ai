<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Model;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use Override;

/**
 * @mixin IdeHelperConversation
 */
final class Conversation extends Model
{
    #[Override]
    protected $table = 'ai_conversations';

    #[Override]
    protected $fillable = [
        'user_id',
        'title',
        'system_message',
        'metadata',
        'memory_enabled',
        'summary',
    ];

    private bool $softDeletesEnabled = false;

    private bool $versionStrategy = false;

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
        return $this->hasMany(Message::class)->oldest();
    }

    /**
     * Get the summaries for the conversation.
     */
    public function summaries(): HasMany
    {
        return $this->hasMany(ConversationSummary::class)->latest();
    }

    /**
     * Add a message to the conversation.
     */
    public function addMessage(string $role, string $content, ?array $metadata = null): Message
    {
        /** @var Message */
        return $this->messages()->create([
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get messages formatted for NeuronAI agent chat history.
     *
     * @return NeuronMessage[]
     */
    public function getMessagesForNeuron(): array
    {
        $messages = [];

        foreach ($this->messages as $message) {
            $role = match ($message->role) {
                'assistant' => MessageRole::ASSISTANT,
                default => MessageRole::USER,
            };

            $messages[] = new NeuronMessage($role, $message->content);
        }

        return $messages;
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'memory_enabled' => 'boolean',
        ];
    }
}
