<?php

declare(strict_types=1);

namespace Modules\AI\Services\Translation;

use Closure;
use Exception;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Agents\ChatAgent;
use NeuronAI\Chat\Messages\UserMessage;

final readonly class AiTranslationService implements TranslationServiceInterface
{
    private const string SYSTEM_PROMPT = 'You are a professional translator. Translate the provided text accurately while preserving formatting, tone, and meaning. Return ONLY the translation, without any explanations or additional text.';

    public function __construct(
        private ?Closure $chatAgentFactory = null,
    ) {}

    public function translate(string $text, string $from_locale, string $to_locale): string
    {
        if ($text === '' || $text === '0') {
            return $text;
        }

        $provider = config('ai.features.translation.default_provider');

        try {
            $agent = $this->makeChatAgent($this->resolveProvider($provider));

            $prompt = "Translate the following text from {$from_locale} to {$to_locale}:\n\n{$text}";
            $response = $agent->chat(new UserMessage($prompt));

            return mb_trim($response->getMessage()->getContent());
        } catch (Exception $e) {
            Log::error('AI translation error', [
                'error' => $e->getMessage(),
                'provider' => $provider,
                'from' => $from_locale,
                'to' => $to_locale,
            ]);

            throw $e;
        }
    }

    public function translateBatch(array $texts, string $from_locale, string $to_locale): array
    {
        $translations = [];

        foreach ($texts as $text) {
            $translations[] = $this->translate($text, $from_locale, $to_locale);
        }

        return $translations;
    }

    /**
     * Resolve the provider name for translations.
     * When the translation provider is 'ai' or 'deepl', fallback to chat default.
     */
    private function resolveProvider(?string $provider): ?string
    {
        return match ($provider) {
            'openai', 'ollama', 'mistral', 'anthropic' => $provider,
            default => null,
        };
    }

    private function makeChatAgent(?string $provider): ChatAgent
    {
        if ($this->chatAgentFactory instanceof Closure) {
            return ($this->chatAgentFactory)($provider);
        }

        /** @var ChatAgent */
        return ChatAgent::make(
            providerName: $provider,
            systemPrompt: self::SYSTEM_PROMPT,
        );
    }
}
