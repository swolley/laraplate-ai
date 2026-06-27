<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\AI\Enums\AITables;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Model;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\Message as NeuronMessage;
use Override;

/**
 * @property int|null $id
 * @property int $user_id
 * @property string|null $title
 * @property string|null $system_message
 * @property array<string, mixed>|null $metadata
 * @property bool $memory_enabled
 * @property string|null $summary
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @mixin \Eloquent
 * @mixin IdeHelperConversation
 */
final class Conversation extends Model
{
    /**
     * @var string
     */
    #[Override]
    protected $table = AITables::Conversations->value;

    #[Override]
    protected $fillable = [
        'user_id',
        'title',
        'system_message',
        'metadata',
        'memory_enabled',
        'summary',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->oldest();
    }

    /**
     * @return HasMany<ConversationSummary, $this>
     */
    public function summaries(): HasMany
    {
        return $this->hasMany(ConversationSummary::class)->latest();
    }

    /**
     * Add a message to the conversation.
     *
     * @param  array<string, mixed>|null  $metadata
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
