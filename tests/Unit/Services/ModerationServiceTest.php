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
