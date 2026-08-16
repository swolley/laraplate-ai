<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use function ai_config_bool;
use function ai_config_string;

use Closure;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\Core\Events\AiTextGenerationRequested;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

/**
 * Answers Core's {@see AiTextGenerationRequested} event with an AI-generated
 * rewrite, when the optional text-generation feature is enabled. It is the AI
 * side of an optional seam: the requester (e.g. SAO's ownership phrasing)
 * depends only on Core, and this listener — which depends only on Core's event —
 * fills the response when present. Any failure leaves the event unfulfilled so
 * the requester falls back to its own deterministic text.
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
        if (! ai_config_bool('ai.features.text_generation.enabled', false)) {
            return;
        }

        if ($event->isFulfilled()) {
            return;
        }

        try {
            $response = $this->makeChatAgent()->chat(new UserMessage($event->prompt));
            $text = mb_trim($response->getMessage()->getContent() ?? '');
        } catch (Throwable) {
            // Leave the event unfulfilled: the requester falls back deterministically.
            return;
        }

        if ($text !== '') {
            $event->fulfill($text);
        }
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
