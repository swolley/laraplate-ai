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

it('validates correct JSON output', function (): void {
    $service = new GuardrailsService;

    expect($service->validateJsonOutput('{"key": "value"}'))->toBeTrue()
        ->and($service->validateJsonOutput('[1, 2, 3]'))->toBeTrue()
        ->and($service->validateJsonOutput('"string"'))->toBeTrue();
});

it('rejects invalid JSON output', function (): void {
    $service = new GuardrailsService;

    expect($service->validateJsonOutput('not json'))->toBeFalse()
        ->and($service->validateJsonOutput('{invalid}'))->toBeFalse()
        ->and($service->validateJsonOutput(''))->toBeFalse();
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
