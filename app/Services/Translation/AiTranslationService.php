<?php

declare(strict_types=1);

namespace Modules\AI\Services\Translation;

use Closure;
use Exception;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Enums\AiModelFeature;
use NeuronAI\Chat\Messages\UserMessage;

final readonly class AiTranslationService implements TranslationServiceInterface
{
    private const string SYSTEM_PROMPT = 'You are a professional translator. Translate the provided text accurately while preserving formatting, tone, and meaning. Return ONLY the translation, without any explanations or additional text.';

    public function __construct(
        private ?Closure $chatAgentFactory = null,
        private ?string $provider = null,
        private ?string $model = null,
    ) {}

    public function translate(string $text, string $from_locale, string $to_locale): string
    {
        if ($text === '' || $text === '0') {
            return $text;
        }

        try {
            $agent = $this->makeChatAgent();

            $prompt = "Translate the following text from {$from_locale} to {$to_locale}:\n\n{$text}";
            $response = $agent->chat(new UserMessage($prompt));

            return mb_trim($response->getMessage()->getContent() ?? '');
        } catch (Exception $e) {
            Log::error('AI translation error', [
                'error' => $e->getMessage(),
                'provider' => $this->provider,
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

    private function makeChatAgent(): ChatAgent
    {
        if ($this->chatAgentFactory instanceof Closure) {
            return ($this->chatAgentFactory)($this->provider);
        }

        if ($this->provider === null) {
            return ChatAgent::forFeature(AiModelFeature::Translation, self::SYSTEM_PROMPT);
        }

        /** @var ChatAgent */
        return ChatAgent::make(
            providerName: $this->provider,
            systemPrompt: self::SYSTEM_PROMPT,
            model: $this->model,
        );
    }
}
