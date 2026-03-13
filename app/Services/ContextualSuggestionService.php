<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Closure;
use Exception;
use Illuminate\Support\Facades\Cache;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Models\ContextualSuggestion;
use Modules\Core\Models\User;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * Service for generating contextual AI suggestions based on UI context.
 * Features rate limiting and caching to prevent excessive API calls.
 */
final readonly class ContextualSuggestionService
{
    private const string RATE_LIMIT_KEY_PREFIX = 'ai:suggestion:rate:';

    private const string CACHE_KEY_PREFIX = 'ai:suggestion:cache:';

    private const string SUGGESTION_SYSTEM_PROMPT = <<<'PROMPT'
You are a helpful assistant providing brief, actionable suggestions based on the user's current context.
Keep suggestions concise (1-2 sentences), relevant, and helpful.
Focus on improving productivity or highlighting useful features.
Respond in the same language as the context.
PROMPT;

    public function __construct(
        private ?Closure $chatAgentFactory = null,
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

        $cache_key = $this->getCacheKey($user, $context);
        $cached_suggestion = Cache::get($cache_key);

        if ($cached_suggestion !== null) {
            return $this->createSuggestionRecord($user, $context, $cached_suggestion);
        }

        try {
            $suggestion = $this->generateSuggestionText($context);

            if ($suggestion === null || $suggestion === '') {
                return null;
            }

            $cache_ttl = config('ai.features.contextual_suggestions.cache_ttl', 3600);
            Cache::put($cache_key, $suggestion, $cache_ttl);

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
        return ContextualSuggestion::query()->forUser($user->id)
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

    private function updateRateLimit(User $user): void
    {
        $key = self::RATE_LIMIT_KEY_PREFIX . $user->id;
        $cooldown_minutes = config('ai.features.contextual_suggestions.cooldown_minutes', 5);
        Cache::put($key, now(), $cooldown_minutes * 60);
    }

    private function getCacheKey(User $user, array $context): string
    {
        $context_hash = md5(json_encode($context, JSON_THROW_ON_ERROR));

        return self::CACHE_KEY_PREFIX . $user->id . ':' . $context_hash;
    }

    private function generateSuggestionText(array $context): ?string
    {
        $agent = $this->makeChatAgent();

        $prompt = $this->buildPromptFromContext($context);

        $response = $agent->chat(new UserMessage($prompt));

        $text = mb_trim($response->getMessage()->getContent());

        return $text !== '' ? $text : null;
    }

    private function makeChatAgent(): ChatAgent
    {
        if ($this->chatAgentFactory instanceof Closure) {
            return ($this->chatAgentFactory)();
        }

        /** @var ChatAgent */
        return ChatAgent::make(systemPrompt: self::SUGGESTION_SYSTEM_PROMPT); // @codeCoverageIgnore
    }

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

    private function createSuggestionRecord(User $user, array $context, string $suggestion): ContextualSuggestion
    {
        return ContextualSuggestion::query()->create([
            'user_id' => $user->id,
            'context' => $context,
            'suggestion' => $suggestion,
        ]);
    }
}
