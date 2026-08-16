<?php

declare(strict_types=1);

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
