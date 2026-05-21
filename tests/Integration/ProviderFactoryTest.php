<?php

declare(strict_types=1);

use Modules\AI\Ai\Providers\ProviderFactory;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\OpenAI;

it('creates an OpenAI provider when configured', function (): void {
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('ai.providers.openai.model', 'gpt-4o-mini');

    $provider = ProviderFactory::make('openai');

    expect($provider)->toBeInstanceOf(OpenAI::class);
});

it('creates an Ollama provider when configured', function (): void {
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');
    config()->set('ai.providers.ollama.model', 'llama3.2:3b');

    $provider = ProviderFactory::make('ollama');

    expect($provider)->toBeInstanceOf(Ollama::class);
});

it('creates a Mistral provider when configured', function (): void {
    config()->set('ai.providers.mistral.api_key', 'test-key');
    config()->set('ai.providers.mistral.model', 'mistral-large-latest');

    $provider = ProviderFactory::make('mistral');

    expect($provider)->toBeInstanceOf(Mistral::class);
});

it('creates an Anthropic provider when configured', function (): void {
    config()->set('ai.providers.anthropic.api_key', 'test-key');
    config()->set('ai.providers.anthropic.model', 'claude-sonnet-4-20250514');

    $provider = ProviderFactory::make('anthropic');

    expect($provider)->toBeInstanceOf(Anthropic::class);
});

it('throws exception for unsupported provider', function (): void {
    ProviderFactory::make('non-existent');
})->throws(Exception::class, 'Unsupported AI provider: non-existent');

it('uses default provider from config when none specified', function (): void {
    config()->set('ai.features.chat.default_provider', 'ollama');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');
    config()->set('ai.providers.ollama.model', 'llama3.2:3b');

    $provider = ProviderFactory::make();

    expect($provider)->toBeInstanceOf(Ollama::class);
});

it('throws when OpenAI API key is missing', function (): void {
    config()->set('ai.providers.openai.api_key', '');

    ProviderFactory::make('openai');
})->throws(Exception::class, 'OpenAI API key is not configured');

it('throws when Mistral API key is missing', function (): void {
    config()->set('ai.providers.mistral.api_key', '');

    ProviderFactory::make('mistral');
})->throws(Exception::class, 'Mistral API key is not configured');

it('throws when Anthropic API key is missing', function (): void {
    config()->set('ai.providers.anthropic.api_key', '');

    ProviderFactory::make('anthropic');
})->throws(Exception::class, 'Anthropic API key is not configured');
