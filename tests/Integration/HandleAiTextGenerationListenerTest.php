<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Listeners\HandleAiTextGenerationListener;
use Modules\Core\Events\AiTextGenerationRequested;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\UserMessage;

beforeEach(function (): void {
    config()->set('ai.features.text_generation.enabled', true);
});

/**
 * A ChatAgent whose one-shot chat() returns the given content.
 */
function fakeAgentReturning(string $content): ChatAgent
{
    $handler = Mockery::mock(AgentHandler::class);
    $handler->shouldReceive('getMessage')->andReturn(new AssistantMessage($content));

    $agent = Mockery::mock(ChatAgent::class);
    $agent->shouldReceive('chat')->andReturn($handler);

    return $agent;
}

it('leaves the request unfulfilled when the feature is disabled', function (): void {
    config()->set('ai.features.text_generation.enabled', false);

    $event = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    new HandleAiTextGenerationListener()->handle($event);

    expect($event->isFulfilled())->toBeFalse();
});

it('fulfils the request with the generated text when enabled', function (): void {
    $handler = Mockery::mock(AgentHandler::class);
    $handler->shouldReceive('getMessage')->andReturn(new AssistantMessage('Ada Lovelace owns this area.'));

    $agent = Mockery::mock(ChatAgent::class);
    $agent->shouldReceive('chat')
        ->with(Mockery::type(UserMessage::class))
        ->andReturn($handler);

    $event = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    new HandleAiTextGenerationListener(chatAgentFactory: fn () => $agent)->handle($event);

    expect($event->response)->toBe('Ada Lovelace owns this area.')
        ->and($event->isFulfilled())->toBeTrue();
});

it('does not overwrite an already-fulfilled request', function (): void {
    $agent = Mockery::mock(ChatAgent::class);
    $agent->shouldNotReceive('chat');

    $event = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    $event->fulfill('already done');
    new HandleAiTextGenerationListener(chatAgentFactory: fn () => $agent)->handle($event);

    expect($event->response)->toBe('already done');
});

it('leaves the request unfulfilled when the model returns empty text', function (): void {
    $message = Mockery::mock(Message::class);
    $message->shouldReceive('getContent')->andReturn('');

    $handler = Mockery::mock(AgentHandler::class);
    $handler->shouldReceive('getMessage')->andReturn($message);

    $agent = Mockery::mock(ChatAgent::class);
    $agent->shouldReceive('chat')->andReturn($handler);

    $event = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    new HandleAiTextGenerationListener(chatAgentFactory: fn () => $agent)->handle($event);

    expect($event->isFulfilled())->toBeFalse();
});

it('leaves the request unfulfilled when the model throws', function (): void {
    $agent = Mockery::mock(ChatAgent::class);
    $agent->shouldReceive('chat')->andThrow(new Exception('LLM down'));

    $event = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    new HandleAiTextGenerationListener(chatAgentFactory: fn () => $agent)->handle($event);

    expect($event->isFulfilled())->toBeFalse();
});

it('no-ops once the per-purpose rate limit is exhausted', function (): void {
    config()->set('ai.features.text_generation.rate_limit.max', 1);
    config()->set('ai.features.text_generation.rate_limit.per_seconds', 60);

    $listener = new HandleAiTextGenerationListener(chatAgentFactory: fn () => fakeAgentReturning('Ada owns this.'));

    $first = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    $second = new AiTextGenerationRequested('rewrite that', 'sao.ownership_suggestion');
    $listener->handle($first);
    $listener->handle($second);

    expect($first->isFulfilled())->toBeTrue()
        ->and($second->isFulfilled())->toBeFalse();
});

it('serves a cached response without calling the model again', function (): void {
    config()->set('ai.features.text_generation.cache_ttl_seconds', 60);

    $prompt = 'rewrite this';
    new HandleAiTextGenerationListener(chatAgentFactory: fn () => fakeAgentReturning('Ada owns this.'))
        ->handle(new AiTextGenerationRequested($prompt, 'sao.ownership_suggestion'));

    // A second listener whose model would throw still resolves from the cache.
    $throwing = Mockery::mock(ChatAgent::class);
    $throwing->shouldReceive('chat')->andThrow(new Exception('should not be called'));
    $second = new AiTextGenerationRequested($prompt, 'sao.ownership_suggestion');

    new HandleAiTextGenerationListener(chatAgentFactory: fn () => $throwing)->handle($second);

    expect($second->response)->toBe('Ada owns this.');
});

it('caps the output on a word boundary', function (): void {
    config()->set('ai.features.text_generation.max_output_chars', 20);

    $event = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    new HandleAiTextGenerationListener(chatAgentFactory: fn () => fakeAgentReturning('one two three four five six seven'))
        ->handle($event);

    expect(mb_strlen((string) $event->response))->toBeLessThanOrEqual(20)
        ->and($event->response)->not->toEndWith(' ');
});

it('strips control characters and collapses whitespace', function (): void {
    $event = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    new HandleAiTextGenerationListener(chatAgentFactory: fn () => fakeAgentReturning("Ada\n\n  Lovelace\towns\x07 this."))
        ->handle($event);

    expect($event->response)->toBe('Ada Lovelace owns this.');
});

it('logs the outcome of an attempt', function (): void {
    Log::spy();

    $event = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    new HandleAiTextGenerationListener(chatAgentFactory: fn () => fakeAgentReturning('Ada owns this.'))
        ->handle($event);

    Log::shouldHaveReceived('info')->withArgs(
        static fn (string $message, array $context): bool => $message === 'ai.text_generation'
            && ($context['outcome'] ?? null) === 'fulfilled'
            && ($context['purpose'] ?? null) === 'sao.ownership_suggestion',
    )->once();
});
