<?php

declare(strict_types=1);

use Modules\AI\Services\LlmSearchService;

it('can be instantiated with explicit provider', function (): void {
    $service = new LlmSearchService('ollama');
    expect($service)->toBeInstanceOf(LlmSearchService::class);
});

it('degrades to the raw query when intent extraction fails', function (): void {
    // An unsupported provider makes the agent throw; the service must fall back
    // to the raw query (no keywords/filters) instead of breaking retrieval.
    $service = new LlmSearchService('__unsupported_provider__');

    $intent = $service->extractSearchIntent('climate change effects');

    expect($intent['keywords'])->toBe([])
        ->and($intent['filters'])->toBe([])
        ->and($intent['query_expansion']['must'])->toBe('climate change effects');
});

it('returns an empty plan when plan generation fails', function (): void {
    $service = new LlmSearchService('__unsupported_provider__');

    expect($service->generateSearchPlan('climate change effects'))->toBe([]);
});
