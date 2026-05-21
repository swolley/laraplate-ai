<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\ChatAgent;
use NeuronAI\Providers\AIProviderInterface;

it('creates a ChatAgent via static make', function (): void {
    config()->set('ai.features.chat.default_provider', 'ollama');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');
    config()->set('ai.providers.ollama.model', 'llama3.2:3b');

    $agent = ChatAgent::make();

    expect($agent)->toBeInstanceOf(ChatAgent::class);
});

it('uses default system prompt when none provided', function (): void {
    config()->set('ai.features.chat.default_provider', 'ollama');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');

    $agent = ChatAgent::make();

    $reflection = new ReflectionMethod($agent, 'instructions');
    $instructions = $reflection->invoke($agent);

    expect($instructions)->toBe('You are a helpful AI assistant.');
});

it('uses custom system prompt when provided', function (): void {
    config()->set('ai.features.chat.default_provider', 'ollama');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');

    $agent = ChatAgent::make(systemPrompt: 'You are a translator.');

    $reflection = new ReflectionMethod($agent, 'instructions');
    $instructions = $reflection->invoke($agent);

    expect($instructions)->toBe('You are a translator.');
});

it('resolves provider via ProviderFactory', function (): void {
    config()->set('ai.features.chat.default_provider', 'ollama');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');
    config()->set('ai.providers.ollama.model', 'llama3.2:3b');

    $agent = ChatAgent::make();

    $reflection = new ReflectionMethod($agent, 'provider');
    $provider = $reflection->invoke($agent);

    expect($provider)->toBeInstanceOf(AIProviderInterface::class);
});

it('resolves specific provider when name is given', function (): void {
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('ai.providers.openai.model', 'gpt-4o-mini');

    $agent = ChatAgent::make(providerName: 'openai');

    $reflection = new ReflectionMethod($agent, 'provider');
    $provider = $reflection->invoke($agent);

    expect($provider)->toBeInstanceOf(AIProviderInterface::class);
});
