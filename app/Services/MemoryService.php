<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Exception;
use LLPhant\Chat\ChatInterface;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\ConversationSummary;

final class MemoryService
{
    private const int SUMMARY_THRESHOLD = 20; // Messages before triggering summary

    private const string SUMMARY_SYSTEM_PROMPT = <<<'PROMPT'
You are a conversation summarizer. Create a concise summary of the conversation that captures:
1. Main topics discussed
2. Key decisions or conclusions
3. Important context for future reference

Be brief but comprehensive. Write in the same language as the conversation.
PROMPT;

    private const string FACTS_SYSTEM_PROMPT = <<<'PROMPT'
Extract key facts from this conversation as a JSON array of strings.
Focus on: user preferences, important information shared, decisions made.
Return ONLY a valid JSON array, no other text.
Example: ["User prefers dark mode", "Project deadline is March 15"]
PROMPT;

    /**
     * Check if conversation should be summarized based on message count.
     */
    public function shouldSummarize(Conversation $conversation): bool
    {
        if (! $conversation->memory_enabled) {
            return false;
        }

        if (! config('ai.features.chat.enable_summary', false)) {
            return false;
        }

        $message_count = $conversation->messages()->count();
        $threshold = config('ai.features.chat.summary_threshold', self::SUMMARY_THRESHOLD);

        // Check if we already have a recent summary
        $last_summary = $conversation->summaries()->first();

        if ($last_summary !== null) {
            $messages_since_summary = $message_count - $last_summary->message_count;

            return $messages_since_summary >= $threshold;
        }

        return $message_count >= $threshold;
    }

    /**
     * Generate a summary of the conversation using LLM.
     */
    public function summarizeConversation(Conversation $conversation, ChatInterface $chat): string
    {
        $messages = $conversation->messages()->oldest()
            ->get(['role', 'content']);

        if ($messages->isEmpty()) {
            return '';
        }

        $conversation_text = $messages
            ->map(fn ($m): string => ucfirst((string) $m->role) . ': ' . $m->content)
            ->implode("\n\n");

        // Include existing summary for context if available
        $context = '';

        if ($conversation->summary) {
            $context = "Previous summary:\n{$conversation->summary}\n\nNew messages:\n";
        }

        $chat->setSystemMessage(self::SUMMARY_SYSTEM_PROMPT);

        return $chat->generateText($context . $conversation_text);
    }

    /**
     * Extract key facts from the conversation.
     *
     * @return string[]
     */
    public function extractFacts(Conversation $conversation, ChatInterface $chat): array
    {
        $messages = $conversation->messages()->oldest()
            ->get(['role', 'content']);

        if ($messages->isEmpty()) {
            return [];
        }

        $conversation_text = $messages
            ->map(fn ($m): string => ucfirst((string) $m->role) . ': ' . $m->content)
            ->implode("\n\n");

        $chat->setSystemMessage(self::FACTS_SYSTEM_PROMPT);

        try {
            $response = $chat->generateText($conversation_text);
            $facts = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            return is_array($facts) ? $facts : [];
        } catch (Exception) {
            return [];
        }
    }

    /**
     * Create a summary snapshot and update conversation summary.
     */
    public function createSummarySnapshot(Conversation $conversation, ChatInterface $chat): ConversationSummary
    {
        $summary = $this->summarizeConversation($conversation, $chat);
        $facts = $this->extractFacts($conversation, $chat);
        $message_count = $conversation->messages()->count();

        // Update conversation's rolling summary
        $conversation->update(['summary' => $summary]);

        // Create historical snapshot
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
}
