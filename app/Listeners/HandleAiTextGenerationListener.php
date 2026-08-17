<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use function ai_config_bool;
use function ai_config_int;
use function ai_config_string;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\Core\Events\AiTextGenerationRequested;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

/**
 * Answers Core's {@see AiTextGenerationRequested} event with an AI-generated
 * rewrite, when the optional text-generation feature is enabled. It is the AI
 * side of an optional seam: the requester (e.g. SAO's ownership phrasing)
 * depends only on Core, and this listener — which depends only on Core's event —
 * fills the response when present. Any failure or a tripped guard leaves the
 * event unfulfilled so the requester falls back to its own deterministic text.
 *
 * Cost is bounded: an optional short-TTL cache serves repeats, a per-purpose
 * rate limit caps bursts, and the output is length-capped and sanitized. Every
 * attempt is logged with its outcome (no prompt body, no secrets).
 */
final readonly class HandleAiTextGenerationListener
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
    You rewrite short internal notes to read naturally. Preserve every fact and
    any exact names given. Do not invent information. Reply with the rewritten
    text only.
    PROMPT;

    public function __construct(private ?Closure $chatAgentFactory = null) {}

    public function handle(AiTextGenerationRequested $event): void
    {
        if (! ai_config_bool('ai.features.text_generation.enabled', false) || $event->isFulfilled()) {
            return;
        }

        $cacheKey = $this->cacheKey($event);
        $ttl = ai_config_int('ai.features.text_generation.cache_ttl_seconds', 0);

        if ($ttl > 0) {
            $cached = Cache::get($cacheKey);

            if (is_string($cached) && $cached !== '') {
                $event->fulfill($cached);
                $this->log($event->purpose, 'cached', 0, mb_strlen($cached));

                return;
            }
        }

        if ($this->rateLimited($event->purpose)) {
            $this->log($event->purpose, 'rate_limited', 0, 0);

            return;
        }

        $startedAt = microtime(true);

        try {
            $response = $this->makeChatAgent()->chat(new UserMessage($event->prompt));
            $text = $this->sanitize((string) ($response->getMessage()->getContent() ?? ''));
        } catch (Throwable) {
            $this->log($event->purpose, 'error', $this->elapsedMs($startedAt), 0);

            return;
        }

        if ($text === '') {
            $this->log($event->purpose, 'empty', $this->elapsedMs($startedAt), 0);

            return;
        }

        if ($ttl > 0) {
            Cache::put($cacheKey, $text, $ttl);
        }

        $event->fulfill($text);
        $this->log($event->purpose, 'fulfilled', $this->elapsedMs($startedAt), mb_strlen($text));
    }

    private function rateLimited(string $purpose): bool
    {
        $max = ai_config_int('ai.features.text_generation.rate_limit.max', 0);

        if ($max <= 0) {
            return false;
        }

        $key = 'ai:text_generation:' . $purpose;

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return true;
        }

        RateLimiter::hit($key, ai_config_int('ai.features.text_generation.rate_limit.per_seconds', 60));

        return false;
    }

    /**
     * Trim, drop control characters, collapse whitespace, and cap the length on
     * a word boundary — so a stray newline or an over-long generation cannot
     * reach the caller unshaped.
     */
    private function sanitize(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = mb_trim((string) preg_replace('/\s+/', ' ', $text));

        $max = ai_config_int('ai.features.text_generation.max_output_chars', 500);

        if ($max > 0 && $max < mb_strlen($text)) {
            $clipped = mb_substr($text, 0, $max);
            $lastSpace = mb_strrpos($clipped, ' ');
            $text = mb_trim($lastSpace !== false && $lastSpace > 0 ? mb_substr($clipped, 0, $lastSpace) : $clipped);
        }

        return $text;
    }

    private function cacheKey(AiTextGenerationRequested $event): string
    {
        return 'ai:text_generation:' . hash('sha256', $event->purpose . '|' . $event->prompt);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function log(string $purpose, string $outcome, int $latencyMs, int $outputChars): void
    {
        Log::info('ai.text_generation', [
            'purpose' => $purpose,
            'provider' => ai_config_string('ai.features.text_generation.default_provider'),
            'outcome' => $outcome,
            'latency_ms' => $latencyMs,
            'output_chars' => $outputChars,
        ]);
    }

    private function makeChatAgent(): ChatAgent
    {
        if ($this->chatAgentFactory instanceof Closure) {
            return ($this->chatAgentFactory)();
        }

        /** @var ChatAgent */
        return ChatAgent::make( // @codeCoverageIgnore
            providerName: ai_config_string('ai.features.text_generation.default_provider'),
            systemPrompt: self::SYSTEM_PROMPT,
        );
    }
}
