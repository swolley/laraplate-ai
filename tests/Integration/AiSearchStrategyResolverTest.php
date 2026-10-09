<?php

declare(strict_types=1);

use Modules\AI\Search\AiSearchStrategyResolver;
use Modules\AI\Services\CrossEncoderService;
use Modules\AI\Services\LlmQueryIntentParser;
use Modules\AI\Services\SearchEmbedder;
use Modules\AI\Services\SearchOrchestratorAgent;
use Modules\Core\Search\Contracts\IQueryIntentParser;
use Modules\Core\Search\Contracts\IReranker;
use Modules\Core\Search\Contracts\ISearchPlanner;
use Modules\Core\Search\Contracts\ISearchStrategyResolver;
use Modules\Core\Search\Enums\SearchMode;
use Modules\Core\Search\Services\FallbackSearchPlanner;
use Modules\Core\Search\Services\HeuristicReranker;
use Modules\Core\Search\Services\SimpleQueryIntentParser;

beforeEach(function (): void {
    config()->set('ai.features.search_orchestration.enabled', true);
});

it('binds the strategy contract to the AI resolver', function (): void {
    expect(app(ISearchStrategyResolver::class))->toBeInstanceOf(AiSearchStrategyResolver::class);
});

it('leaves Core\'s own components bound to the search contracts', function (): void {
    expect(app(ISearchPlanner::class))->toBeInstanceOf(FallbackSearchPlanner::class)
        ->and(app(IReranker::class))->toBeInstanceOf(HeuristicReranker::class)
        ->and(app(IQueryIntentParser::class))->toBeInstanceOf(SimpleQueryIntentParser::class);
});

it('serves fast with Core\'s components and builds no AI class', function (): void {
    $strategy = app(ISearchStrategyResolver::class)->resolve(SearchMode::Fast);

    expect($strategy->applied_mode)->toBe(SearchMode::Fast)
        ->and($strategy->planner)->toBeInstanceOf(FallbackSearchPlanner::class)
        ->and($strategy->embedder)->toBeNull()
        ->and($strategy->degraded_reason)->toBeNull();
});

it('serves balanced with the embedder and no LLM', function (): void {
    $strategy = app(ISearchStrategyResolver::class)->resolve(SearchMode::Balanced);

    expect($strategy->applied_mode)->toBe(SearchMode::Balanced)
        ->and($strategy->embedder)->toBeInstanceOf(SearchEmbedder::class)
        ->and($strategy->planner)->toBeInstanceOf(FallbackSearchPlanner::class)
        ->and($strategy->intent_parser)->toBeInstanceOf(SimpleQueryIntentParser::class)
        ->and($strategy->degraded_reason)->toBeNull();
});

it('serves deep with the LLM components, the cross-encoder and the embedder', function (): void {
    $strategy = app(ISearchStrategyResolver::class)->resolve(SearchMode::Deep);

    expect($strategy->applied_mode)->toBe(SearchMode::Deep)
        ->and($strategy->planner)->toBeInstanceOf(SearchOrchestratorAgent::class)
        ->and($strategy->intent_parser)->toBeInstanceOf(LlmQueryIntentParser::class)
        ->and($strategy->reranker)->toBeInstanceOf(CrossEncoderService::class)
        ->and($strategy->embedder)->toBeInstanceOf(SearchEmbedder::class)
        ->and($strategy->max_retries)->toBe(2)
        ->and($strategy->degraded_reason)->toBeNull();
});

it('degrades balanced and deep to fast with a reason when the overlay is switched off', function (SearchMode $mode): void {
    config()->set('ai.features.search_orchestration.enabled', false);

    $strategy = app(ISearchStrategyResolver::class)->resolve($mode);

    expect($strategy->applied_mode)->toBe(SearchMode::Fast)
        ->and($strategy->planner)->toBeInstanceOf(FallbackSearchPlanner::class)
        ->and($strategy->embedder)->toBeNull()
        ->and($strategy->degraded_reason)->toBe('search_orchestration_disabled');
})->with([SearchMode::Balanced, SearchMode::Deep]);

it('degrades to fast when the embedder cannot be built', function (): void {
    app()->bind(SearchEmbedder::class, static fn (): never => throw new RuntimeException('no embedding service'));

    $strategy = app(ISearchStrategyResolver::class)->resolve(SearchMode::Deep);

    expect($strategy->applied_mode)->toBe(SearchMode::Fast)
        ->and($strategy->degraded_reason)->toBe('embedder_unavailable');
});
