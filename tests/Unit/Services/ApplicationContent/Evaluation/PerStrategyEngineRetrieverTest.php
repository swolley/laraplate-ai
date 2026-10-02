<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Services\ApplicationContent\Evaluation\PerStrategyEngineRetriever;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\Services\EnsembleSearchService;

/**
 * An evaluation case names the language its results are requested in. A model whose `LocaleScope` shows
 * only the rows translated in the current locale returns nothing for an English-only document when the
 * search runs in another locale, so every case has to run in the locale it requests.
 */
function per_strategy_retriever_with(callable $search): PerStrategyEngineRetriever
{
    $ensemble = Mockery::mock(EnsembleSearchService::class);
    $ensemble->shouldReceive('search')->andReturnUsing($search);

    return new PerStrategyEngineRetriever($ensemble);
}

function per_strategy_empty_result(): AdvancedSearchResult
{
    return new AdvancedSearchResult(hits: [], total: 0, page: 1, perPage: 5, totalPages: 1, meta: []);
}

beforeEach(function (): void {
    config(['app.locale' => 'it']);
    LocaleContext::set('it');
});

it('runs the search in the locale of the case and restores the previous one', function (): void {
    $seen = null;
    $retriever = per_strategy_retriever_with(function () use (&$seen): AdvancedSearchResult {
        $seen = LocaleContext::get();

        return per_strategy_empty_result();
    });

    $retriever->retrieve(Mockery::mock(Model::class), 'top secret satellites', false, 5, null, 'en');

    expect($seen)->toBe('en')
        ->and(LocaleContext::get())->toBe('it');
});

it('restores the previous locale when the search fails', function (): void {
    $retriever = per_strategy_retriever_with(static function (): never {
        throw new RuntimeException('engine down');
    });

    expect(fn () => $retriever->retrieve(Mockery::mock(Model::class), 'query', false, 5, null, 'en'))
        ->toThrow(RuntimeException::class, 'engine down');
    expect(LocaleContext::get())->toBe('it');
});

it('leaves the locale alone when the case names none', function (): void {
    $seen = null;
    $retriever = per_strategy_retriever_with(function () use (&$seen): AdvancedSearchResult {
        $seen = LocaleContext::get();

        return per_strategy_empty_result();
    });

    $retriever->retrieve(Mockery::mock(Model::class), 'query', false, 5, null);

    expect($seen)->toBe('it');
});
