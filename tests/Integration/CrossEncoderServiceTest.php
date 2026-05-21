<?php

declare(strict_types=1);

use Modules\AI\Services\CrossEncoderService;
use Modules\Core\Search\Contracts\IReranker;

it('implements IReranker contract', function (): void {
    expect(new CrossEncoderService('http://test:8001/score'))->toBeInstanceOf(IReranker::class);
});

it('returns empty array for empty pairs', function (): void {
    $service = new CrossEncoderService('http://test:8001/score');
    expect($service->score([]))->toBe([]);
});
