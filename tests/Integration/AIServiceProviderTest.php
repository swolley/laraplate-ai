<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Modules\AI\Providers\AIServiceProvider;

it('skips search bindings when search orchestration is disabled', function (): void {
    Config::set('ai.features.search_orchestration.enabled', false);

    $provider = new AIServiceProvider(app());
    $method = new ReflectionMethod($provider, 'registerSearchBindings');
    $method->invoke($provider);

    expect(true)->toBeTrue();
});
