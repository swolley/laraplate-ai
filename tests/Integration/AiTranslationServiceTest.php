<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Services\Translation\AiTranslationService;

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

it('translate calls ChatAgent and returns translated text', function (): void {
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
    $result = $service->translateBatch(['Hello', 'World'], 'en', 'it');

    expect($result)->toBe(['Ciao', 'Mondo']);
});

it('translate logs error and throws on exception', function (): void {
    $mockAgent = Mockery::mock(ChatAgent::class);
    $mockAgent->shouldReceive('chat')->andThrow(new Exception('Translation failed'));

    $service = new AiTranslationService(
        chatAgentFactory: fn (?string $provider) => $mockAgent,
    );

    expect(fn (): string => $service->translate('Hello', 'en', 'it'))->toThrow(Exception::class, 'Translation failed');
});

it('hands its provider to the agent factory', function (): void {
    $received_provider = 'untouched';
    $handler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $handler->shouldReceive('getMessage')->andReturn(new NeuronAI\Chat\Messages\AssistantMessage('Ciao'));
    $agent = Mockery::mock(ChatAgent::class);
    $agent->shouldReceive('chat')->andReturn($handler);

    $service = new AiTranslationService(
        chatAgentFactory: function (?string $provider) use (&$received_provider, $agent): ChatAgent {
            $received_provider = $provider;

            return $agent;
        },
        provider: 'mistral',
        model: 'mistral-large-latest',
    );

    expect($service->translate('hello', 'en', 'it'))->toBe('Ciao')
        ->and($received_provider)->toBe('mistral');
});
