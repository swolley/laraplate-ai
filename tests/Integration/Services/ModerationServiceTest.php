<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Data\ModerationVerdictData;
use Modules\AI\Enums\ModerationVerdict;
use Modules\AI\Services\ModerationService;
use Modules\Core\Data\ModerationInput;
use Modules\Core\Data\ModerationRequest;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;

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

/**
 * A moderation service whose model is Neuron's fake provider, answering with the given replies in
 * order; with none, it fails like a provider that is down. What it was asked is read from $provider.
 *
 * @param  list<string>  $replies
 */
function moderationServiceAnswering(array $replies, ?FakeAIProvider &$provider = null): ModerationService
{
    $provider = new FakeAIProvider(...array_map(static fn (string $reply): AssistantMessage => new AssistantMessage($reply), $replies));

    return new ModerationService(static fn (): ChatAgent => ChatAgent::make(systemPrompt: 'Moderate the subject.')->setAiProvider($provider));
}

it('rejects empty subject text without calling the LLM', function (): void {
    $result = moderationServiceAnswering(['{"verdict":"approve"}'], $provider)->analyze(moderationRequest(''));

    expect($result->verdict)->toBe(ModerationVerdict::Reject)
        ->and($result->safeToAutoApprove)->toBeFalse()
        ->and($provider->getRecorded())->toBe([]);
});

it('analyzes a subject into the verdict the model returns', function (string $reply, ModerationVerdict $verdict, float $confidence, array $categories, bool $safe): void {
    $result = moderationServiceAnswering([$reply], $provider)->analyze(moderationRequest());

    expect($result->verdict)->toBe($verdict)
        ->and($result->confidence)->toBe($confidence)
        ->and($result->categories)->toBe($categories)
        ->and($result->safeToAutoApprove)->toBe($safe)
        ->and($provider->getRecorded())->toHaveCount(1);
})->with([
    'approve' => ['{"verdict":"approve","confidence":0.97,"categories":[],"reason":"On topic","safe_to_auto_approve":true}', ModerationVerdict::Approve, 0.97, [], true],
    'reject' => ['{"verdict":"reject","confidence":0.92,"categories":["spam"],"reason":"Link farm","safe_to_auto_approve":false}', ModerationVerdict::Reject, 0.92, ['spam'], false],
    'uncertain' => ['{"verdict":"uncertain","confidence":0.41,"categories":[],"reason":"Ambiguous tone","safe_to_auto_approve":false}', ModerationVerdict::Uncertain, 0.41, [], false],
    'a whole number for the confidence' => ['{"verdict":"approve","confidence":1,"categories":[],"reason":"Fine","safe_to_auto_approve":true}', ModerationVerdict::Approve, 1.0, [], true],
    'the answer in a markdown fence' => ["```json\n{\"verdict\":\"reject\",\"confidence\":0.8,\"categories\":[\"hate\"],\"reason\":\"Abuse\",\"safe_to_auto_approve\":false}\n```", ModerationVerdict::Reject, 0.8, ['hate'], false],
]);

it('sends the schema of the verdict and the prompt of the request', function (): void {
    moderationServiceAnswering(['{"verdict":"approve","confidence":0.9,"categories":[],"reason":"Fine","safe_to_auto_approve":true}'], $provider)->analyze(moderationRequest('Nice post'));

    $record = $provider->getRecorded()[0];
    $schema = json_encode($record->structuredSchema);

    expect((string) $record->messages[0]->getContent())->toBe("Subject:\nNice post")
        ->and($record->method)->toBe('structured')
        ->and($record->structuredClass)->toBe(ModerationVerdictData::class)
        ->and($schema)->toContain('safe_to_auto_approve')->toContain('confidence')->toContain('reason');
});

it('asks again with what was wrong when the first answer does not fit', function (string $first): void {
    $result = moderationServiceAnswering([
        $first,
        '{"verdict":"approve","confidence":0.9,"categories":[],"reason":"Polite","safe_to_auto_approve":true}',
    ], $provider)->analyze(moderationRequest());

    $provider->assertCallCount(2);

    expect($result->verdict)->toBe(ModerationVerdict::Approve);
})->with([
    'prose' => ['I think this comment is fine.'],
    'a verdict that is not one' => ['{"verdict":"maybe","confidence":0.9,"categories":[],"reason":"Hm","safe_to_auto_approve":false}'],
    'a confidence above one' => ['{"verdict":"approve","confidence":85,"categories":[],"reason":"Fine","safe_to_auto_approve":true}'],
    'no reason' => ['{"verdict":"approve","confidence":0.9,"categories":[],"reason":"","safe_to_auto_approve":true}'],
]);

it('leaves the decision to a person when no answer ever fits, after one retry', function (): void {
    $result = moderationServiceAnswering(['not json', 'still not json'], $provider)->analyze(moderationRequest());

    $provider->assertCallCount(2);

    expect($result->verdict)->toBe(ModerationVerdict::Uncertain)
        ->and($result->confidence)->toBe(0.0)
        ->and($result->safeToAutoApprove)->toBeFalse();
});

it('falls back to uncertain when the model cannot be reached', function (): void {
    $result = moderationServiceAnswering([])->analyze(moderationRequest());

    expect($result->verdict)->toBe(ModerationVerdict::Uncertain)
        ->and($result->safeToAutoApprove)->toBeFalse();
});
