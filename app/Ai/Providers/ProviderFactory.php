<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers;

use function ai_config_string;

use InvalidArgumentException;
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
    public static function make(?string $provider = null): AIProviderInterface
    {
        $provider ??= ai_config_string('ai.features.chat.default_provider', 'ollama');

        return match ($provider) {
            'openai' => self::createOpenAI(),
            'ollama' => self::createOllama(),
            'mistral' => self::createMistral(),
            'anthropic' => self::createAnthropic(),
            default => throw new InvalidArgumentException("Unsupported AI provider: {$provider}"),
        };
    }

    private static function createOpenAI(): OpenAI
    {
        $api_key = ai_config_string('ai.providers.openai.api_key');
        throw_if($api_key === '', ConfigurationException::class, 'OpenAI API key is not configured');

        return new OpenAI(
            key: $api_key,
            model: ai_config_string('ai.providers.openai.model', 'gpt-4o-mini'),
        );
    }

    private static function createOllama(): Ollama
    {
        return new Ollama(
            url: ai_config_string('ai.providers.ollama.api_url', 'http://localhost:11434'),
            model: ai_config_string('ai.providers.ollama.model', 'llama3.2:3b'),
        );
    }

    private static function createMistral(): Mistral
    {
        $api_key = ai_config_string('ai.providers.mistral.api_key');
        throw_if($api_key === '', ConfigurationException::class, 'Mistral API key is not configured');

        return new Mistral(
            key: $api_key,
            model: ai_config_string('ai.providers.mistral.model', 'mistral-large-latest'),
        );
    }

    private static function createAnthropic(): Anthropic
    {
        $api_key = ai_config_string('ai.providers.anthropic.api_key');
        throw_if($api_key === '', ConfigurationException::class, 'Anthropic API key is not configured');

        return new Anthropic(
            key: $api_key,
            model: ai_config_string('ai.providers.anthropic.model', 'claude-sonnet-4-20250514'),
        );
    }
}
