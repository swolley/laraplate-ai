<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Services\Translation\AiTranslationService;

beforeEach(function (): void {
    config()->set('ai.features.translation.default_provider', 'ollama');
});

it('translate returns empty string as-is', function (): void {
    $service = new AiTranslationService;

    expect($service->translate('', 'en', 'it'))->toBe('');
});

it('translate returns zero text as-is', function (): void {
    $service = new AiTranslationService;

    expect($service->translate('0', 'en', 'it'))->toBe('0');
});

it('translateBatch returns empty and zero as-is', function (): void {
    $service = new AiTranslationService;

    $result = $service->translateBatch(['', '0'], 'en', 'it');

    expect($result)->toBe(['', '0']);
});

it('translateBatch returns empty array for empty input', function (): void {
    $service = new AiTranslationService;

    expect($service->translateBatch([], 'en', 'it'))->toBe([]);
});

/**
 * The provider failing is the point, not which exception it raises. Driving it
 * through the injected factory keeps the assertion on translate()'s contract —
 * log, then rethrow — instead of on whatever a real endpoint answers. Without
 * the factory this test reached the configured provider over the network and
 * passed only because the call failed: an unknown provider name resolves to
 * null, which means "use the default", not "refuse".
 */
it('translate rethrows the provider failure after logging it', function (): void {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(static fn (string $message): bool => $message === 'AI translation error');

    $failing_agent = Mockery::mock(ChatAgent::class);
    $failing_agent->shouldReceive('chat')->andThrow(new RuntimeException('provider unavailable'));

    $service = new AiTranslationService(chatAgentFactory: fn (): ChatAgent => $failing_agent);

    expect(fn (): string => $service->translate('hello', 'en', 'it'))
        ->toThrow(RuntimeException::class, 'provider unavailable');
});

it('resolves an unknown provider name to the configured default', function (): void {
    config()->set('ai.features.translation.default_provider', 'invalid');

    $received_provider = 'untouched';
    $handler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $handler->shouldReceive('getMessage')
        ->andReturn(new NeuronAI\Chat\Messages\AssistantMessage('Ciao'));

    $agent = Mockery::mock(ChatAgent::class);
    $agent->shouldReceive('chat')->andReturn($handler);

    $service = new AiTranslationService(chatAgentFactory: function (?string $provider) use (&$received_provider, $agent): ChatAgent {
        $received_provider = $provider;

        return $agent;
    });

    expect($service->translate('hello', 'en', 'it'))->toBe('Ciao')
        ->and($received_provider)->toBeNull();
});

it('translate calls ChatAgent and returns translated text', function (): void {
    config()->set('ai.features.translation.default_provider', 'ollama');

    $mockAgentHandler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $mockAgentHandler->shouldReceive('getMessage')
        ->andReturn(new NeuronAI\Chat\Messages\AssistantMessage('Ciao'));

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')
        ->with(Mockery::on(fn (NeuronAI\Chat\Messages\UserMessage $msg): bool => str_contains((string) $msg->getContent(), 'Hello') && str_contains((string) $msg->getContent(), 'en') && str_contains((string) $msg->getContent(), 'it')))
        ->andReturn($mockAgentHandler);

    $service = new AiTranslationService(
        chatAgentFactory: fn (?string $provider) => $mockAgent,
    );

    $result = $service->translate('Hello', 'en', 'it');

    expect($result)->toBe('Ciao');
});

it('translateBatch calls translate for each text', function (): void {
    $mockAgentHandler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $mockAgentHandler->shouldReceive('getMessage')
        ->andReturn(
            new NeuronAI\Chat\Messages\AssistantMessage('Ciao'),
            new NeuronAI\Chat\Messages\AssistantMessage('Mondo'),
        );

    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')->andReturn($mockAgentHandler);

    $service = new AiTranslationService(
        chatAgentFactory: fn (?string $provider) => $mockAgent,
    );

    config()->set('ai.features.translation.default_provider', 'openai');
    $result = $service->translateBatch(['Hello', 'World'], 'en', 'it');

    expect($result)->toBe(['Ciao', 'Mondo']);
});

it('translate logs error and throws on exception', function (): void {
    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')->andThrow(new Exception('Translation failed'));

    $service = new AiTranslationService(
        chatAgentFactory: fn (?string $provider) => $mockAgent,
    );

    config()->set('ai.features.translation.default_provider', 'ollama');

    expect(fn (): string => $service->translate('Hello', 'en', 'it'))->toThrow(Exception::class, 'Translation failed');
});

it('resolveProvider maps known providers correctly', function (): void {
    $service = new AiTranslationService;
    $method = new ReflectionMethod($service, 'resolveProvider');

    expect($method->invoke($service, 'openai'))->toBe('openai')
        ->and($method->invoke($service, 'ollama'))->toBe('ollama')
        ->and($method->invoke($service, 'mistral'))->toBe('mistral')
        ->and($method->invoke($service, 'anthropic'))->toBe('anthropic')
        ->and($method->invoke($service, 'deepl'))->toBeNull()
        ->and($method->invoke($service, 'ai'))->toBeNull()
        ->and($method->invoke($service, null))->toBeNull();
});
