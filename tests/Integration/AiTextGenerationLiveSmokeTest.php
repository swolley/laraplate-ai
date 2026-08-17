<?php

declare(strict_types=1);

use Modules\AI\Listeners\HandleAiTextGenerationListener;
use Modules\Core\Events\AiTextGenerationRequested;

/**
 * A single live round-trip against the configured provider. Skipped by default:
 * it runs only with AI_LIVE_TESTS=1 and real provider credentials, so normal CI
 * never hits a live model. Everything else about the listener is covered offline
 * in HandleAiTextGenerationListenerTest.
 */
it('rewrites text through the live provider while preserving the name', function (): void {
    config()->set('ai.features.text_generation.enabled', true);
    // No injected factory: this uses the real ChatAgent / provider stack.
    $event = new AiTextGenerationRequested(
        'Suggested owner: Ada Lovelace, from CODEOWNERS entry on app/Billing/Invoice.php. Review before assigning.',
        'sao.ownership_suggestion',
    );

    new HandleAiTextGenerationListener()->handle($event);

    expect($event->isFulfilled())->toBeTrue()
        ->and($event->response)->toContain('Ada Lovelace');
})->skip(getenv('AI_LIVE_TESTS') !== '1', 'Set AI_LIVE_TESTS=1 with provider credentials to run the live smoke test.');
