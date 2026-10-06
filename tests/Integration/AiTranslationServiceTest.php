<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Services\Translation\AiTranslationService;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;

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
 * A translation service whose model is Neuron's fake provider, answering with the given replies in
 * order; with none, it fails like a provider that is down. What it was asked is read from $provider.
 *
 * @param  list<string>  $replies
 */
function translationServiceAnswering(array $replies, ?FakeAIProvider &$provider = null, ?string $service_provider = null): AiTranslationService
{
    $provider = new FakeAIProvider(...array_map(static fn (string $reply): AssistantMessage => new AssistantMessage($reply), $replies));
    $model = $provider;

    return new AiTranslationService(
        chatAgentFactory: static fn (?string $name): ChatAgent => ChatAgent::make(systemPrompt: 'Translate.')->setAiProvider($model),
        provider: $service_provider,
    );
}

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

    $service = translationServiceAnswering([]);

    expect(fn (): string => $service->translate('hello', 'en', 'it'))->toThrow(Exception::class);
});

it('translate asks the model for the text between the two locales and returns the translation', function (): void {
    $service = translationServiceAnswering(["  Ciao\n"], $provider);

    expect($service->translate('Hello', 'en', 'it'))->toBe('Ciao');

    $prompt = (string) $provider->getRecorded()[0]->messages[0]->getContent();

    expect($prompt)->toContain('from en to it')->toContain('Hello');
});

it('translateBatch translates each text in order', function (): void {
    $service = translationServiceAnswering(['Ciao', 'Mondo'], $provider);

    expect($service->translateBatch(['Hello', 'World'], 'en', 'it'))->toBe(['Ciao', 'Mondo']);

    $provider->assertCallCount(2);
});

it('hands its provider to the agent factory', function (): void {
    $received_provider = 'untouched';
    $provider = new FakeAIProvider(new AssistantMessage('Ciao'));

    $service = new AiTranslationService(
        chatAgentFactory: function (?string $name) use (&$received_provider, $provider): ChatAgent {
            $received_provider = $name;

            return ChatAgent::make(systemPrompt: 'Translate.')->setAiProvider($provider);
        },
        provider: 'mistral',
        model: 'mistral-large-latest',
    );

    expect($service->translate('hello', 'en', 'it'))->toBe('Ciao')
        ->and($received_provider)->toBe('mistral');
});
