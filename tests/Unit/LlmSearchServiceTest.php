<?php

declare(strict_types=1);

use Modules\AI\Services\LlmSearchService;

it('can be instantiated with explicit provider', function (): void {
    $service = new LlmSearchService('ollama');
    expect($service)->toBeInstanceOf(LlmSearchService::class);
});
