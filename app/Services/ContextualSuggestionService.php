<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use LLPhant\Chat\ChatInterface;
use Modules\AI\Models\ContextualSuggestion;
use Modules\Core\Models\User;

/**
 * Service for generating contextual AI suggestions based on UI context.
 * Features rate limiting and caching to prevent excessive API calls.
 */
final class ContextualSuggestionService
{
    private const RATE_LIMIT_KEY_PREFIX = 'ai:suggestion:rate:';

    private const CACHE_KEY_PREFIX = 'ai:suggestion:cache:';

    private const SUGGESTION_SYSTEM_PROMPT = <<<'PROMPT'
You are a helpful assistant providing brief, actionable suggestions based on the user's current context.
Keep suggestions concise (1-2 sentences), relevant, and helpful.
Focus on improving productivity or highlighting useful features.
Respond in the same language as the context.
PROMPT;

    public function __construct(
        private readonly ChatService $chatService,
    ) {}

    /**
     * Generate a contextual suggestion for a user.
     * Returns null if rate limited or suggestions disabled.
     */
    public function generateSuggestion(User $user, array $context): ?ContextualSuggestion
    {
        if (! config('ai.features.contextual_suggestions.enabled', false)) {
            return null;
        }

        if ($this->isRateLimited($user)) {
            return null;
        }

        // Check cache for similar context
        $cache_key = $this->getCacheKey($user, $context);
        $cached_suggestion = Cache::get($cache_key);

        if ($cached_suggestion !== null) {
            return $this->createSuggestionRecord($user, $context, $cached_suggestion);
        }

        try {
            $chat = $this->chatService->getChatInstance();
            $suggestion = $this->generateSuggestionText($chat, $context);

            if ($suggestion === null || $suggestion === '') {
                return null;
            }

            // Cache the suggestion
            $cache_ttl = config('ai.features.contextual_suggestions.cache_ttl', 3600);
            Cache::put($cache_key, $suggestion, $cache_ttl);

            // Update rate limit
            $this->updateRateLimit($user);

            return $this->createSuggestionRecord($user, $context, $suggestion);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Get pending (not dismissed) suggestions for a user.
     */
    public function getPendingSuggestions(User $user, int $limit = 5): \Illuminate\Database\Eloquent\Collection
    {
        return ContextualSuggestion::forUser($user->id)
            ->notDismissed()
            ->recent(60)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Dismiss a suggestion.
     */
    public function dismissSuggestion(ContextualSuggestion $suggestion): void
    {
        $suggestion->dismiss();
    }

    /**
     * Check if user is rate limited.
     */
    private function isRateLimited(User $user): bool
    {
        $key = self::RATE_LIMIT_KEY_PREFIX . $user->id;
        $last_suggestion_at = Cache::get($key);

        if ($last_suggestion_at === null) {
            return false;
        }

        $cooldown_minutes = config('ai.features.contextual_suggestions.cooldown_minutes', 5);

        return $cooldown_minutes > now()->diffInMinutes($last_suggestion_at);
    }

    /**
     * Update rate limit timestamp.
     */
    private function updateRateLimit(User $user): void
    {
        $key = self::RATE_LIMIT_KEY_PREFIX . $user->id;
        $cooldown_minutes = config('ai.features.contextual_suggestions.cooldown_minutes', 5);
        Cache::put($key, now(), $cooldown_minutes * 60);
    }

    /**
     * Generate cache key for context.
     */
    private function getCacheKey(User $user, array $context): string
    {
        $context_hash = md5(json_encode($context, JSON_THROW_ON_ERROR));

        return self::CACHE_KEY_PREFIX . $user->id . ':' . $context_hash;
    }

    /**
     * Generate suggestion text using LLM.
     */
    private function generateSuggestionText(ChatInterface $chat, array $context): ?string
    {
        $chat->setSystemMessage(self::SUGGESTION_SYSTEM_PROMPT);

        $prompt = $this->buildPromptFromContext($context);

        $response = $chat->generateText($prompt);

        return mb_trim($response) ?: null;
    }

    /**
     * Build prompt from context.
     */
    private function buildPromptFromContext(array $context): string
    {
        $parts = [];

        if (isset($context['page'])) {
            $parts[] = "Current page: {$context['page']}";
        }

        if (isset($context['action'])) {
            $parts[] = "Current action: {$context['action']}";
        }

        if (isset($context['data'])) {
            $data_summary = is_array($context['data'])
                ? json_encode($context['data'], JSON_THROW_ON_ERROR)
                : (string) $context['data'];
            $parts[] = "Context data: {$data_summary}";
        }

        if ($parts === []) {
            return 'Provide a helpful general suggestion for the user.';
        }

        return implode("\n", $parts) . "\n\nProvide a brief, helpful suggestion.";
    }

    /**
     * Create suggestion record in database.
     */
    private function createSuggestionRecord(User $user, array $context, string $suggestion): ContextualSuggestion
    {
        return ContextualSuggestion::create([
            'user_id' => $user->id,
            'context' => $context,
            'suggestion' => $suggestion,
        ]);
    }
}
