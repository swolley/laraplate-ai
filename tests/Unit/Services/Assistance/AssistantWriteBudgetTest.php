<?php

declare(strict_types=1);

use Modules\AI\Services\Assistance\Writes\AssistantWriteBudget;

it('lets a turn apply a fixed number of writes and refuses the next without taking it', function (): void {
    $budget = new AssistantWriteBudget;

    for ($i = 0; $i < AssistantWriteBudget::MAX_WRITES_PER_TURN; $i++) {
        expect($budget->take())->toBeTrue();
    }

    expect($budget->take())->toBeFalse()
        ->and($budget->used())->toBe(AssistantWriteBudget::MAX_WRITES_PER_TURN);
});

it('starts every turn from zero', function (): void {
    $budget = new AssistantWriteBudget;

    while ($budget->take()) {
        // exhaust it
    }

    $budget->startTurn();

    expect($budget->used())->toBe(0)
        ->and($budget->take())->toBeTrue();
});

it('is one instance for a request', function (): void {
    expect(app(AssistantWriteBudget::class))->toBe(app(AssistantWriteBudget::class));
});
