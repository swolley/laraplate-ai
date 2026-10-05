<?php

declare(strict_types=1);

use Modules\AI\Services\ApplicationContent\Evaluation\RerankerRun;
use Modules\Core\Search\DTOs\AdvancedSearchResult;

/**
 * An evaluation asks for the reranked ordering of every case, but a reranker that is down leaves the fused
 * order and says so in `meta['reranked']`. Without reading it, the report presents the fused order as the
 * reranker's result and a missing service looks like a reranker that adds nothing.
 */
function reranker_run_result(mixed $reranked): AdvancedSearchResult
{
    return new AdvancedSearchResult(
        hits: [],
        total: 0,
        page: 1,
        perPage: 5,
        totalPages: 0,
        meta: $reranked === 'absent' ? [] : ['reranked' => $reranked],
    );
}

it('summarises how many of the requested cases the reranker ran for', function (int $requested, int $ran, string $status): void {
    expect(RerankerRun::summary($requested, $ran))->toBe(['requested' => $requested, 'ran' => $ran, 'status' => $status, 'model' => null]);
})->with([
    'every case' => [5, 5, 'ran'],
    'some cases' => [5, 2, 'partial'],
    'no case' => [5, 0, 'not_run'],
    'no case at all' => [0, 0, 'not_run'],
]);

it('reads from the result whether the reranker ran, and only a true flag counts', function (mixed $flag, bool $ran): void {
    expect(RerankerRun::ranIn(reranker_run_result($flag)))->toBe($ran);
})->with([
    'true' => [true, true],
    'false' => [false, false],
    'absent' => ['absent', false],
    'a truthy string' => ['yes', false],
]);

it('says nothing when the reranker ran or the report has no reranker block', function (?int $requested, ?int $ran): void {
    $report = $requested === null ? [] : ['reranker' => RerankerRun::summary($requested, (int) $ran)];

    expect(RerankerRun::warning($report))->toBeNull();
})->with([
    'ran' => [4, 4],
    'no block' => [null, null],
]);

it('warns that the reranked figures are the fused order when the reranker did not run', function (): void {
    $message = RerankerRun::warning(['reranker' => RerankerRun::summary(61, 0)]);

    expect($message)->toContain('did not run')
        ->and($message)->toContain('0 of 61')
        ->and($message)->toContain('fused');
});

it('warns with the count when the reranker ran only for some cases', function (): void {
    $message = RerankerRun::warning(['reranker' => RerankerRun::summary(10, 4)]);

    expect($message)->toContain('4 of 10')
        ->and($message)->toContain('only partly');
});

it('carries the model that scored the cases in the summary', function (): void {
    expect(RerankerRun::summary(4, 4, 'org/reranker'))->toBe(['requested' => 4, 'ran' => 4, 'status' => 'ran', 'model' => 'org/reranker']);
});

it('reads the model that scored a result from its meta, when it named one', function (mixed $meta, ?string $model): void {
    $result = new AdvancedSearchResult(hits: [], total: 0, page: 1, perPage: 5, totalPages: 0, meta: $meta);

    expect(RerankerRun::modelIn($result))->toBe($model);
})->with([
    'a model' => [['reranked' => true, 'reranker_model' => 'org/reranker'], 'org/reranker'],
    'an empty name' => [['reranked' => true, 'reranker_model' => ''], null],
    'not a string' => [['reranked' => true, 'reranker_model' => 5], null],
    'absent' => [['reranked' => true], null],
]);

it('names the one model that scored every case, and every model when they differ', function (array $models, ?string $expected): void {
    expect(RerankerRun::modelOf($models))->toBe($expected);
})->with([
    'none named' => [[null, null], null],
    'no cases' => [[], null],
    'one model' => [['org/a', 'org/a', null], 'org/a'],
    'two models' => [['org/b', 'org/a'], 'org/a, org/b'],
]);
