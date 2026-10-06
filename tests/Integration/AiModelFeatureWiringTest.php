<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Ai\Agents\DocumentationAgent;
use Modules\AI\Ai\Providers\ProviderFactory;
use Modules\AI\Listeners\HandleAiTextGenerationListener;
use Modules\AI\Services\ContextualSuggestionService;
use Modules\AI\Services\GuardrailsService;
use Modules\AI\Services\LlmSearchService;
use Modules\AI\Services\MemoryService;
use Modules\AI\Services\ModerationService;
use Modules\AI\Tests\Stubs\RecordingHttpClient;
use Modules\Core\Data\ModerationInput;
use Modules\Core\Data\ModerationRequest;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;

beforeEach(function (): void {
    config()->set('ai.providers.ollama.api_url', 'http://ollama.test');
    config()->set('ai.providers.anthropic.api_key', 'ak-test');
});

/**
 * @return array{0: ?string, 1: ?string}
 */
function wiredAgent(ChatAgent $agent): array
{
    return [
        (new ReflectionProperty($agent, 'providerName'))->getValue($agent),
        (new ReflectionProperty($agent, 'model'))->getValue($agent),
    ];
}

function invokeWiring(object $target, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($target, $method))->invoke($target, ...$arguments);
}

it('builds every chat-family agent on its own feature choice', function (): void {
    config()->set('ai.features.chat.model', 'ollama:chat-model');
    config()->set('ai.features.text_generation.model', 'ollama:text-model');
    config()->set('ai.features.moderation.model', 'ollama:moderation-model');
    config()->set('ai.features.search_orchestration.model', 'ollama:search-model');
    config()->set('ai.features.contextual_suggestions.model', 'ollama:suggestion-model');
    config()->set('ai.features.chat.summary.model', 'ollama:summary-model');
    config()->set('ai.features.guardrails.model', 'ollama:guard-model');

    $request = new ModerationRequest(
        input: new ModerationInput(subjectText: 'x', locale: 'en', contextSections: [], profile: 'test'),
        systemPrompt: 'Moderate.',
        userPrompt: 'x',
    );

    expect(wiredAgent(ChatAgent::forFeature(Modules\AI\Enums\AiModelFeature::Chat)))->toBe(['ollama', 'chat-model'])
        ->and(wiredAgent(invokeWiring(new HandleAiTextGenerationListener(), 'makeChatAgent')))->toBe(['ollama', 'text-model'])
        ->and(wiredAgent(invokeWiring(new ModerationService, 'createAgent', $request)))->toBe(['ollama', 'moderation-model'])
        ->and(wiredAgent(invokeWiring(new LlmSearchService(), 'createAgent', 'prompt')))->toBe(['ollama', 'search-model'])
        ->and(wiredAgent(invokeWiring(new ContextualSuggestionService(), 'makeChatAgent')))->toBe(['ollama', 'suggestion-model'])
        ->and(wiredAgent(invokeWiring(new MemoryService(), 'makeChatAgent', 'prompt')))->toBe(['ollama', 'summary-model'])
        ->and(wiredAgent(invokeWiring(new GuardrailsService(), 'makeChatAgent')))->toBe(['ollama', 'guard-model']);
});

it('keeps an explicit provider override on search orchestration', function (): void {
    config()->set('ai.features.search_orchestration.model', 'ollama:search-model');

    expect(wiredAgent(invokeWiring(new LlmSearchService('anthropic'), 'createAgent', 'prompt')))->toBe(['anthropic', null]);
});

it('answers FAQ questions on the FAQ choice', function (): void {
    config()->set('ai.features.faq.model', 'ollama:faq-model');

    $provider = invokeWiring(DocumentationAgent::make(), 'provider');
    $client = new RecordingHttpClient(['message' => ['content' => 'ok']]);
    $provider->setHttpClient($client);
    $provider->chat(new UserMessage('hi'));

    expect($client->lastRequest->body['model'])->toBe('faq-model');
});

it('falls back to the chat choice when no provider is named', function (): void {
    config()->set('ai.features.chat.model', 'anthropic:claude-sonnet-5');

    expect(ProviderFactory::make())->toBeInstanceOf(Anthropic::class);
});
