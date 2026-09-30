<?php

declare(strict_types=1);

use Modules\AI\Enums\ModerationVerdict;
use Modules\AI\Services\GuardrailsService;
use Modules\AI\Services\ModerationService;
use Modules\Core\Data\ModerationInput;
use Modules\Core\Data\ModerationRequest;

function moderationRequest(string $body = 'Nice post'): ModerationRequest
{
    $input = new ModerationInput(
        subjectText: $body,
        locale: 'en',
        contextSections: [
            'Article title' => 'Title',
            'Article excerpt' => 'Excerpt',
        ],
        profile: 'test.profile',
    );

    return new ModerationRequest(
        input: $input,
        systemPrompt: 'Moderate the subject.',
        userPrompt: "Subject:\n{$body}",
    );
}

it('rejects empty subject text without calling the LLM', function (): void {
    $service = new ModerationService(new GuardrailsService());

    $result = $service->analyze(moderationRequest(''));

    expect($result->verdict)->toBe(ModerationVerdict::Reject)
        ->and($result->safeToAutoApprove)->toBeFalse();
});

it('maps valid JSON response to moderation result', function (): void {
    $service = new ModerationService(new GuardrailsService());

    $result = $service->mapResponse(<<<'JSON'
{"verdict":"approve","confidence":0.95,"categories":[],"reason":"OK","safe_to_auto_approve":true}
JSON);

    expect($result->verdict)->toBe(ModerationVerdict::Approve)
        ->and($result->confidence)->toBe(0.95)
        ->and($result->safeToAutoApprove)->toBeTrue();
});

it('returns uncertain when JSON is invalid', function (): void {
    $service = new ModerationService(new GuardrailsService());

    $result = $service->mapResponse('not json');

    expect($result->verdict)->toBe(ModerationVerdict::Uncertain)
        ->and($result->safeToAutoApprove)->toBeFalse();
});

/**
 * A chat agent that answers each call with the next scripted reply, and counts the calls.
 *
 * @param  list<string>  $replies
 */
function scriptedModerationAgent(array $replies, int &$calls): Modules\AI\Ai\Agents\ChatAgent
{
    $agent = Mockery::mock(Modules\AI\Ai\Agents\ChatAgent::class);
    $agent->shouldReceive('chat')
        ->with(Mockery::type(NeuronAI\Chat\Messages\UserMessage::class))
        ->andReturnUsing(static function () use (&$replies, &$calls): NeuronAI\Agent\AgentHandler {
            $calls++;
            $message = Mockery::mock(NeuronAI\Chat\Messages\Message::class);
            $message->shouldReceive('getContent')->andReturn(array_shift($replies) ?? '');
            $handler = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
            $handler->shouldReceive('getMessage')->andReturn($message);

            return $handler;
        });

    return $agent;
}

it('analyzes a subject into the verdict the model returns', function (string $reply, ModerationVerdict $verdict, bool $safe): void {
    $calls = 0;
    $agent = scriptedModerationAgent([$reply], $calls);
    $service = new ModerationService(new GuardrailsService(), static fn () => $agent);

    $result = $service->analyze(moderationRequest());

    expect($result->verdict)->toBe($verdict)
        ->and($result->safeToAutoApprove)->toBe($safe)
        ->and($calls)->toBe(1);
})->with([
    'approve' => ['{"verdict":"approve","confidence":0.97,"categories":[],"reason":"On topic","safe_to_auto_approve":true}', ModerationVerdict::Approve, true],
    'reject' => ['{"verdict":"reject","confidence":0.92,"categories":["spam"],"reason":"Link farm","safe_to_auto_approve":false}', ModerationVerdict::Reject, false],
    'uncertain' => ['{"verdict":"uncertain","confidence":0.41,"categories":[],"reason":"Ambiguous tone","safe_to_auto_approve":false}', ModerationVerdict::Uncertain, false],
]);

it('asks once more for JSON when the first answer is not valid JSON', function (): void {
    $calls = 0;
    $agent = scriptedModerationAgent([
        'I think this comment is fine.',
        '{"verdict":"approve","confidence":0.9,"categories":[],"reason":"Polite","safe_to_auto_approve":true}',
    ], $calls);
    $service = new ModerationService(new GuardrailsService(), static fn () => $agent);

    $result = $service->analyze(moderationRequest());

    expect($calls)->toBe(2)
        ->and($result->verdict)->toBe(ModerationVerdict::Approve);
});

it('falls back to uncertain when the model cannot be reached', function (): void {
    $agent = Mockery::mock(Modules\AI\Ai\Agents\ChatAgent::class);
    $agent->shouldReceive('chat')->andThrow(new RuntimeException('provider down'));
    $service = new ModerationService(new GuardrailsService(), static fn () => $agent);

    $result = $service->analyze(moderationRequest());

    expect($result->verdict)->toBe(ModerationVerdict::Uncertain)
        ->and($result->safeToAutoApprove)->toBeFalse();
});
