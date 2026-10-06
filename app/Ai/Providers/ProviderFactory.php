<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers;

use function ai_config_string;

use InvalidArgumentException;
use Modules\AI\Enums\AiModelFeature;
use Modules\Core\Exceptions\ConfigurationException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\OpenAI;

/**
 * Factory for creating NeuronAI provider instances from application config.
 */
final class ProviderFactory
{
    private const string ANTHROPIC_DEFAULT_MODEL = 'claude-sonnet-4-20250514';

    /**
     * Build a provider. An explicit `$model` overrides the provider's configured
     * default, letting one feature (e.g. text generation) target a different
     * model than another without changing the shared provider config; `null`
     * keeps the configured default. Without a provider, provider and model come
     * from the chat choice in Settings. `$maxOutputTokens` caps what the model may
     * write in one answer, with the parameter each provider names it by; `null`
     * leaves the provider's own limit.
     */
    public static function make(?string $provider = null, ?string $model = null, ?int $maxOutputTokens = null): AIProviderInterface
    {
        if ($provider === null) {
            $choice = AiModelChoice::forFeature(AiModelFeature::Chat);
            $provider = $choice->provider;
            $model ??= $choice->model;
        }

        return match ($provider) {
            'openai' => self::createOpenAI($model, $maxOutputTokens),
            'ollama' => self::createOllama($model, $maxOutputTokens),
            'mistral' => self::createMistral($model, $maxOutputTokens),
            'anthropic' => self::createAnthropic($model, $maxOutputTokens),
            default => throw new InvalidArgumentException("Unsupported AI provider: {$provider}"),
        };
    }

    /**
     * Whether the chat provider chosen in Settings has what it needs to be called (an API key, a
     * URL). It builds the provider and calls nothing.
     */
    public static function isConfigured(): bool
    {
        try {
            self::make();
        } catch (ConfigurationException|InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private static function createOpenAI(?string $model, ?int $maxOutputTokens): OpenAI
    {
        $api_key = ai_config_string('ai.providers.openai.api_key');
        throw_if($api_key === '', ConfigurationException::class, 'OpenAI API key is not configured');

        return new OpenAI(
            key: $api_key,
            model: $model ?? ai_config_string('ai.providers.openai.model', 'gpt-4o-mini'),
            parameters: $maxOutputTokens === null ? [] : ['max_completion_tokens' => $maxOutputTokens],
        );
    }

    private static function createOllama(?string $model, ?int $maxOutputTokens): Ollama
    {
        $url = ai_config_string('ai.providers.ollama.api_url');
        throw_if($url === '', ConfigurationException::class, 'Ollama API URL is not configured');

        // OLLAMA_API_URL is the server base (http://host:11434); neuron-ai joins
        // `chat` onto its base, which must therefore end with /api.
        return new Ollama(
            url: mb_rtrim($url, '/') . '/api',
            model: $model ?? ai_config_string('ai.providers.ollama.model', 'llama3.2:3b'),
            parameters: $maxOutputTokens === null ? [] : ['options' => ['num_predict' => $maxOutputTokens]],
        );
    }

    private static function createMistral(?string $model, ?int $maxOutputTokens): Mistral
    {
        $api_key = ai_config_string('ai.providers.mistral.api_key');
        throw_if($api_key === '', ConfigurationException::class, 'Mistral API key is not configured');

        return new Mistral(
            key: $api_key,
            model: $model ?? ai_config_string('ai.providers.mistral.model', 'mistral-large-latest'),
            parameters: $maxOutputTokens === null ? [] : ['max_tokens' => $maxOutputTokens],
        );
    }

    private static function createAnthropic(?string $model, ?int $maxOutputTokens): Anthropic
    {
        $api_key = ai_config_string('ai.providers.anthropic.api_key');
        throw_if($api_key === '', ConfigurationException::class, 'Anthropic API key is not configured');

        return $maxOutputTokens === null
            ? new Anthropic(key: $api_key, model: $model ?? self::ANTHROPIC_DEFAULT_MODEL)
            : new Anthropic(key: $api_key, model: $model ?? self::ANTHROPIC_DEFAULT_MODEL, max_tokens: $maxOutputTokens);
    }
}
