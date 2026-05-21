<?php

declare(strict_types=1);

use Modules\AI\Services\LlmQueryIntentParser;
use Modules\AI\Services\LlmSearchService;
use Modules\Core\Search\Contracts\IQueryIntentParser;

it('implements IQueryIntentParser contract', function (): void {
    $llm = Mockery::mock(LlmSearchService::class);
    expect(new LlmQueryIntentParser($llm))->toBeInstanceOf(IQueryIntentParser::class);
});

it('maps LLM intent to contract format', function (): void {
    $llm = Mockery::mock(LlmSearchService::class);
    $llm->shouldReceive('extractSearchIntent')->with('climate change')->andReturn([
        'keywords' => ['climate', 'change', 'global warming'],
        'filters' => ['date_range' => null],
        'query_expansion' => ['must' => 'climate change global warming effects'],
    ]);

    $parser = new LlmQueryIntentParser($llm);
    $result = $parser->parse('climate change');

    expect($result['keywords'])->toBe(['climate', 'change', 'global warming']);
    expect($result['date_range'])->toBeNull();
    expect($result['query']['expanded'])->toBe('climate change global warming effects');
});

it('falls back to original query when expansion is missing', function (): void {
    $llm = Mockery::mock(LlmSearchService::class);
    $llm->shouldReceive('extractSearchIntent')->andReturn([
        'keywords' => [],
        'filters' => [],
        'query_expansion' => [],
    ]);

    $parser = new LlmQueryIntentParser($llm);
    $result = $parser->parse('my query');

    expect($result['query']['expanded'])->toBe('my query');
});
