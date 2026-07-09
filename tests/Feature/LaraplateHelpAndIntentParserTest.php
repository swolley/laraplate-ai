<?php

declare(strict_types=1);

use Modules\AI\Console\LaraplateHelpCommand;
use Modules\AI\Services\DocumentationService;
use Modules\AI\Services\LlmSearchService;
use Modules\AI\Services\LlmQueryIntentParser;

it('fails when faq feature is disabled', function (): void {
    config(['ai.features.faq.enabled' => false]);

    $this->artisan(LaraplateHelpCommand::class)
        ->assertFailed();
});

it('fails when rag index is unavailable', function (): void {
    config(['ai.features.faq.enabled' => true]);

    $this->mock(DocumentationService::class, function ($mock): void {
        $mock->shouldReceive('isAvailable')->once()->andReturn(false);
    });

    $this->artisan(LaraplateHelpCommand::class)
        ->assertFailed();
});

it('answers a one-shot question with citations', function (): void {
    config(['ai.features.faq.enabled' => true]);

    $this->mock(DocumentationService::class, function ($mock): void {
        $mock->shouldReceive('isAvailable')->once()->andReturn(true);
        $mock->shouldReceive('answerQuestion')
            ->once()
            ->with('How do modules work?')
            ->andReturn([
                'answer' => 'Modules are self-contained packages.',
                'citations' => [
                    ['source' => 'docs/modules.md', 'score' => 0.912],
                ],
            ]);
    });

    $this->artisan(LaraplateHelpCommand::class, ['--question' => 'How do modules work?'])
        ->expectsOutputToContain('Modules are self-contained packages.')
        ->expectsOutputToContain('docs/modules.md')
        ->assertSuccessful();
});

it('reports empty answers and query failures', function (): void {
    config(['ai.features.faq.enabled' => true]);

    $this->mock(DocumentationService::class, function ($mock): void {
        $mock->shouldReceive('isAvailable')->once()->andReturn(true);
        $mock->shouldReceive('answerQuestion')
            ->once()
            ->andReturn(['answer' => '', 'citations' => []]);
    });

    $this->artisan(LaraplateHelpCommand::class, ['--question' => 'Empty?'])
        ->expectsOutputToContain('(empty answer)')
        ->assertSuccessful();

    $this->mock(DocumentationService::class, function ($mock): void {
        $mock->shouldReceive('isAvailable')->once()->andReturn(true);
        $mock->shouldReceive('answerQuestion')
            ->once()
            ->andThrow(new RuntimeException('RAG offline'));
    });

    $this->artisan(LaraplateHelpCommand::class, ['--question' => 'Fail?'])
        ->expectsOutputToContain('Help query failed: RAG offline')
        ->assertFailed();
});

it('parses llm search intent including date filters', function (): void {
    $llm = Mockery::mock(LlmSearchService::class);
    $llm->shouldReceive('extractSearchIntent')
        ->once()
        ->with('laravel modules')
        ->andReturn([
            'keywords' => ['laravel', 'modules'],
            'filters' => [
                'date_range' => [
                    'from' => '2024-01-01',
                    'to' => 123,
                    'until' => '2024-12-31',
                ],
            ],
            'query_expansion' => ['must' => 'expanded laravel modules'],
        ]);

    $parser = new LlmQueryIntentParser($llm);
    $result = $parser->parse('laravel modules');

    expect($result['keywords'])->toBe(['laravel', 'modules'])
        ->and($result['query']['expanded'])->toBe('expanded laravel modules')
        ->and($result['date_range'])->toBe([
            'from' => '2024-01-01',
            'to' => '123',
            'until' => '2024-12-31',
        ]);
});

it('falls back to original query and null date range when intent is sparse', function (): void {
    $llm = Mockery::mock(LlmSearchService::class);
    $llm->shouldReceive('extractSearchIntent')
        ->once()
        ->andReturn([
            'keywords' => [],
            'filters' => ['date_range' => 'invalid'],
            'query_expansion' => ['must' => ''],
        ]);

    $parser = new LlmQueryIntentParser($llm);
    $result = $parser->parse('original query');

    expect($result['query']['expanded'])->toBe('original query')
        ->and($result['date_range'])->toBeNull();
});

it('returns null date range when filter entries are all invalid', function (): void {
    $llm = Mockery::mock(LlmSearchService::class);
    $llm->shouldReceive('extractSearchIntent')
        ->once()
        ->andReturn([
            'keywords' => ['x'],
            'filters' => ['date_range' => []],
            'query_expansion' => [],
        ]);

    $parser = new LlmQueryIntentParser($llm);
    $result = $parser->parse('query');

    expect($result['date_range'])->toBeNull();
});
