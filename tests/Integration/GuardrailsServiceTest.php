<?php

declare(strict_types=1);

use Modules\AI\Services\GuardrailsService;

it('returns input unchanged when guardrails are disabled', function (): void {
    config()->set('ai.features.guardrails.enabled', false);
    config()->set('ai.features.guardrails.prompt_injection_detection', false);

    $service = new GuardrailsService;

    expect($service->checkPromptInjection('Hello world'))->toBe('Hello world');
});

it('returns input unchanged when prompt injection detection is disabled', function (): void {
    config()->set('ai.features.guardrails.enabled', true);
    config()->set('ai.features.guardrails.prompt_injection_detection', false);

    $service = new GuardrailsService;

    expect($service->checkPromptInjection('some prompt'))->toBe('some prompt');
});

it('detects lakera credentials presence', function (): void {
    config()->set('ai.features.guardrails.lakera_api_key');

    $service = new GuardrailsService;
    $reflection = new ReflectionMethod($service, 'hasLakeraCredentials');

    expect($reflection->invoke($service))->toBeFalse();

    config()->set('ai.features.guardrails.lakera_api_key', 'test-key');

    expect($reflection->invoke($service))->toBeTrue();
});

it('detects empty string as no lakera credentials', function (): void {
    config()->set('ai.features.guardrails.lakera_api_key', '');

    $service = new GuardrailsService;
    $reflection = new ReflectionMethod($service, 'hasLakeraCredentials');

    expect($reflection->invoke($service))->toBeFalse();
});

it('rejects an unexpected lakera payload shape, so the caller falls back to the LLM check', function (): void {
    $service = new GuardrailsService;
    $reflection = new ReflectionMethod($service, 'assertLakeraSafe');

    expect(fn (): mixed => $reflection->invoke($service, ['unexpected' => true]))
        ->toThrow(UnexpectedValueException::class, 'Lakera Guard returned an unexpected response.');
});

it('ignores non-array lakera result entries', function (): void {
    $service = new GuardrailsService;
    $reflection = new ReflectionMethod($service, 'assertLakeraSafe');

    $reflection->invoke($service, ['results' => ['invalid', ['flagged' => false]]]);

    expect(true)->toBeTrue();
});
