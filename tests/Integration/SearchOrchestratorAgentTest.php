<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Modules\AI\Services\LlmSearchService;
use Modules\AI\Services\SearchOrchestratorAgent;
use Modules\Core\Search\Contracts\ISearchPlanner;

beforeEach(function (): void {
    $container = Container::getInstance();

    if (! $container->bound('config')) {
        $container->singleton('config', fn (): Repository => new Repository([
            'search' => [
                'features' => ['reranker' => true],
                'reranker' => ['top_k' => 30],
                'vector_search' => ['enabled' => false],
            ],
        ]));
    }
});

it('implements ISearchPlanner contract', function (): void {
    $llm = Mockery::mock(LlmSearchService::class);
    expect(new SearchOrchestratorAgent($llm))->toBeInstanceOf(ISearchPlanner::class);
});

it('fallbackPlan returns valid structure', function (): void {
    config()->set('search.vector_search.enabled', true);

    $llm = Mockery::mock(LlmSearchService::class);
    $agent = new SearchOrchestratorAgent($llm);
    $plan = $agent->fallbackPlan('test');

    expect($plan)->toHaveKeys(['strategy', 'retrieval', 'ensemble', 'ranking', 'vector', 'filters', 'retry_policy', 'meta']);
    expect($plan['strategy'])->toBe('hybrid');
    expect($plan['meta']['source'])->toBe('fallback_rules');
});

it('disables vector when globally disabled', function (): void {
    config()->set('search.vector_search.enabled', false);

    $llm = Mockery::mock(LlmSearchService::class);
    $agent = new SearchOrchestratorAgent($llm);
    $plan = $agent->fallbackPlan('test query');

    expect($plan['retrieval']['use_vector'])->toBeFalse();
    expect($plan['vector']['enabled'])->toBeFalse();
    expect($plan['strategy'])->toBe('fulltext');
});

it('safePlan falls back on LLM exception', function (): void {
    $llm = Mockery::mock(LlmSearchService::class);
    $llm->shouldReceive('generateSearchPlan')->andThrow(new RuntimeException('LLM unavailable'));

    $agent = new SearchOrchestratorAgent($llm);
    $plan = $agent->safePlan('test query');

    expect($plan)->toHaveKeys(['strategy', 'retrieval', 'ensemble', 'ranking', 'vector', 'filters', 'retry_policy', 'meta']);
    expect($plan['meta']['source'])->toBe('fallback_rules');
});
