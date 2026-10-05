<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;

it('round-trips through JSON', function (): void {
    $state = new EmbeddingSwitchState(
        status: 'running',
        phase: 'embeddings',
        target: 'sentence_transformers:all-MiniLM-L6-v2',
        previous: 'sentence_transformers:intfloat/multilingual-e5-small',
        total: 10,
        done: 4,
        error: null,
        startedAt: '2026-10-05T10:00:00+00:00',
    );

    expect(EmbeddingSwitchState::fromJson($state->toJson()))->toEqual($state);
});

it('degrades to idle on null, empty and invalid JSON', function (?string $json): void {
    $state = EmbeddingSwitchState::fromJson($json);

    expect($state->status)->toBe('idle')
        ->and($state->phase)->toBeNull()
        ->and($state->target)->toBeNull()
        ->and($state->total)->toBe(0)
        ->and($state->done)->toBe(0);
})->with([
    'null' => [null],
    'empty' => [''],
    'garbage' => ['{not json'],
    'scalar' => ['"x"'],
    'unknown status' => ['{"status":"weird"}'],
]);

it('exposes an idle factory', function (): void {
    expect(EmbeddingSwitchState::idle()->status)->toBe('idle');
});
