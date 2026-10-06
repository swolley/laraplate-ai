<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use function ai_config_bool;
use function ai_config_int;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Data\ExtractedFacts;
use Modules\AI\Enums\AiModelFeature;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\ConversationSummary;
use Modules\AI\Models\Message;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

final readonly class MemoryService
{
    /**
     * Times Neuron asks the model again, with what was wrong, when the facts do not fit the schema.
     */
    public const int MAX_RETRIES = 1;

    private const int SUMMARY_THRESHOLD = 20;

    private const string SUMMARY_SYSTEM_PROMPT = <<<'PROMPT'
You are a conversation summarizer. Create a concise summary of the conversation that captures:
1. Main topics discussed
2. Key decisions or conclusions
3. Important context for future reference

Be brief but comprehensive. Write in the same language as the conversation.
PROMPT;

    private const string FACTS_SYSTEM_PROMPT = <<<'PROMPT'
Extract the key facts from this conversation.
Focus on: user preferences, important information shared, decisions made.
Example facts: "User prefers dark mode", "Project deadline is March 15"
PROMPT;

    public function __construct(
        private ?Closure $chatAgentFactory = null,
    ) {}

    /**
     * Check if conversation should be summarized based on message count.
     */
    public function shouldSummarize(Conversation $conversation): bool
    {
        if (! $conversation->memory_enabled) {
            return false;
        }

        if (! ai_config_bool('ai.features.chat.summary.enabled', false)) {
            return false;
        }

        $message_count = $conversation->messages()->count();
        $threshold = ai_config_int('ai.features.chat.summary_threshold', self::SUMMARY_THRESHOLD);

        $last_summary = $conversation->summaries()->first();

        if ($last_summary instanceof ConversationSummary) {
            $messages_since_summary = $message_count - $last_summary->message_count;

            return $messages_since_summary >= $threshold;
        }

        return $message_count >= $threshold;
    }

    /**
     * Generate a summary of the conversation using a NeuronAI agent.
     */
    public function summarizeConversation(Conversation $conversation): string
    {
        /** @var Collection<int, Message> */
        $messages = $conversation->messages()->oldest()
            ->get(['role', 'content']);

        if ($messages->isEmpty()) {
            return '';
        }

        $conversation_text = $messages
            ->map(fn (Message $m): string => ucfirst((string) $m->role) . ': ' . $m->content)
            ->implode("\n\n");

        $context = '';

        if ($conversation->summary) {
            $context = "Previous summary:\n{$conversation->summary}\n\nNew messages:\n";
        }

        $summary_agent = $this->makeChatAgent(self::SUMMARY_SYSTEM_PROMPT);

        $response = $summary_agent->chat(new UserMessage($context . $conversation_text));

        return mb_trim($response->getMessage()->getContent() ?? '');
    }

    /**
     * Extract key facts from the conversation.
     *
     * @return list<string>
     */
    public function extractFacts(Conversation $conversation): array
    {
        /** @var Collection<int, Message> */
        $messages = $conversation->messages()->oldest()
            ->get(['role', 'content']);

        if ($messages->isEmpty()) {
            return [];
        }

        $conversation_text = $messages
            ->map(fn (Message $m): string => ucfirst((string) $m->role) . ': ' . $m->content)
            ->implode("\n\n");

        try {
            $output = $this->makeChatAgent(self::FACTS_SYSTEM_PROMPT)->structured(
                new UserMessage($conversation_text),
                ExtractedFacts::class,
                self::MAX_RETRIES,
            );

            return $output instanceof ExtractedFacts ? $output->toList() : [];
        } catch (Throwable) {
            // Facts are a by-product of a summary: a provider that fails, or an answer that never fits,
            // leaves the snapshot without them.
            return [];
        }
    }

    /**
     * Create a summary snapshot and update conversation summary.
     */
    public function createSummarySnapshot(Conversation $conversation): ConversationSummary
    {
        $summary = $this->summarizeConversation($conversation);
        $facts = $this->extractFacts($conversation);
        $message_count = $conversation->messages()->count();

        $conversation->update(['summary' => $summary]);

        return ConversationSummary::query()->create([
            'conversation_id' => $conversation->id,
            'summary' => $summary,
            'facts' => $facts,
            'message_count' => $message_count,
        ]);
    }

    /**
     * Clear memory for a conversation (forget).
     */
    public function forgetConversation(Conversation $conversation): void
    {
        $conversation->summaries()->delete();
        $conversation->update(['summary' => null]);
    }

    /**
     * Toggle memory for a conversation.
     */
    public function setMemoryEnabled(Conversation $conversation, bool $enabled): void
    {
        $conversation->update(['memory_enabled' => $enabled]);

        if (! $enabled) {
            $this->forgetConversation($conversation);
        }
    }

    /**
     * Get context for a new message (includes summary if available).
     */
    public function getContextForNewMessage(Conversation $conversation): ?string
    {
        if (! $conversation->memory_enabled || ! $conversation->summary) {
            return null;
        }

        return "Previous conversation summary:\n{$conversation->summary}";
    }

    private function makeChatAgent(string $systemPrompt): ChatAgent
    {
        if ($this->chatAgentFactory instanceof Closure) {
            return ($this->chatAgentFactory)($systemPrompt);
        }

        /** @var ChatAgent */
        return ChatAgent::forFeature(AiModelFeature::ChatSummary, $systemPrompt); // @codeCoverageIgnore
    }
}
