<?php

declare(strict_types=1);

use Modules\AI\Services\SearchEmbedder;
use Modules\Core\Search\Contracts\ITextEmbedder;

it('implements ITextEmbedder contract', function (): void {
    $ref = new ReflectionClass(SearchEmbedder::class);
    expect($ref->implementsInterface(ITextEmbedder::class))->toBeTrue();
});

it('has embed method matching the contract', function (): void {
    $ref = new ReflectionClass(SearchEmbedder::class);
    $method = $ref->getMethod('embed');

    expect($method->getNumberOfParameters())->toBe(1);
    expect($method->getReturnType()?->getName())->toBe('array');
});
