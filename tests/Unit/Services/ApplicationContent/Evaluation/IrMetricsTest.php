<?php

declare(strict_types=1);

use Modules\AI\Services\ApplicationContent\Evaluation\IrMetrics;

it('computes per-case precision, recall and nDCG contributions at each cutoff', function (): void {
    $result = IrMetrics::atK(['a', 'b', 'c'], ['b'], [1, 3, 5]);

    expect($result)->toBe([
        'precision' => [1 => 0.0, 3 => 1 / 3, 5 => 0.2],
        'recall' => [1 => 0.0, 3 => 1.0, 5 => 1.0],
        'ndcg' => [1 => 0.0, 3 => (1 / log(3, 2)) / 1, 5 => (1 / log(3, 2)) / 1],
    ]);
});

it('returns zero contributions at every cutoff when there are no hits', function (): void {
    $result = IrMetrics::atK([], ['b'], [1, 3, 5]);

    expect($result)->toBe([
        'precision' => [1 => 0.0, 3 => 0.0, 5 => 0.0],
        'recall' => [1 => 0.0, 3 => 0.0, 5 => 0.0],
        'ndcg' => [1 => 0.0, 3 => 0.0, 5 => 0.0],
    ]);
});

it('returns zero contributions at every cutoff when the relevant id ranks beyond them', function (): void {
    $result = IrMetrics::atK(['h1', 'h2', 'h3', 'h4', 'h5', 'rel'], ['rel'], [1, 3, 5]);

    expect($result)->toBe([
        'precision' => [1 => 0.0, 3 => 0.0, 5 => 0.0],
        'recall' => [1 => 0.0, 3 => 0.0, 5 => 0.0],
        'ndcg' => [1 => 0.0, 3 => 0.0, 5 => 0.0],
    ]);
});

it('caps recall below 1.0 when the expected set is larger than the cutoff', function (): void {
    $expected = ['r1', 'r2', 'r3', 'r4', 'r5', 'r6', 'r7'];
    $result = IrMetrics::atK(['r1', 'r2', 'r3', 'r4', 'r5'], $expected, [5]);

    expect($result)->toBe([
        'precision' => [5 => 1.0],
        'recall' => [5 => 5 / 7],
        'ndcg' => [5 => 1.0],
    ]);
});

it('guards nDCG against a zero IDCG when there are no expected ids', function (): void {
    $result = IrMetrics::atK(['a', 'b', 'c'], [], [1, 3, 5]);

    expect($result)->toBe([
        'precision' => [1 => 0.0, 3 => 0.0, 5 => 0.0],
        'recall' => [1 => 0.0, 3 => 0.0, 5 => 0.0],
        'ndcg' => [1 => 0.0, 3 => 0.0, 5 => 0.0],
    ]);
});
