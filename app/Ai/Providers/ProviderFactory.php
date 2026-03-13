<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers;

use Exception;
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
        $provider ??= (string) config('ai.features.chat.default_provider', 'ollama');

        return match ($provider) {
            'openai' => self::createOpenAI(),
            'ollama' => self::createOllama(),
            'mistral' => self::createMistral(),
            'anthropic' => self::createAnthropic(),
            default => throw new Exception("Unsupported AI provider: {$provider}"),
        };
    }

    private static function createOpenAI(): OpenAI
    {
        $api_key = (string) config('ai.providers.openai.api_key');
        throw_if($api_key === '', Exception::class, 'OpenAI API key is not configured');

        return new OpenAI(
            key: $api_key,
            model: (string) config('ai.providers.openai.model', 'gpt-4o-mini'),
        );
    }

    private static function createOllama(): Ollama
    {
        return new Ollama(
            url: (string) config('ai.providers.ollama.api_url', 'http://localhost:11434'),
            model: (string) config('ai.providers.ollama.model', 'llama3.2:3b'),
        );
    }

    private static function createMistral(): Mistral
    {
        $api_key = (string) config('ai.providers.mistral.api_key');
        throw_if($api_key === '', Exception::class, 'Mistral API key is not configured');

        return new Mistral(
            key: $api_key,
            model: (string) config('ai.providers.mistral.model', 'mistral-large-latest'),
        );
    }

    private static function createAnthropic(): Anthropic
    {
        $api_key = (string) config('ai.providers.anthropic.api_key');
        throw_if($api_key === '', Exception::class, 'Anthropic API key is not configured');

        return new Anthropic(
            key: $api_key,
            model: (string) config('ai.providers.anthropic.model', 'claude-sonnet-4-20250514'),
        );
    }
}
