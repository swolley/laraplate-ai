<?php

declare(strict_types=1);

use Modules\AI\Services\Documentation\Chunking\MarkdownAwareSplitter;
use Modules\AI\Services\Documentation\Chunking\SplitterFactory;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use NeuronAI\RAG\Splitter\SentenceTextSplitter;

it('returns a markdown-aware splitter by default', function (): void {
    config()->set('ai.features.faq.splitter', null);

    expect(SplitterFactory::make())->toBeInstanceOf(MarkdownAwareSplitter::class);
});

it('respects the configured driver for sentence', function (): void {
    config()->set('ai.features.faq.splitter.driver', 'sentence');

    expect(SplitterFactory::make())->toBeInstanceOf(SentenceTextSplitter::class);
});

it('respects the configured driver for delimiter', function (): void {
    config()->set('ai.features.faq.splitter.driver', 'delimiter');

    expect(SplitterFactory::make())->toBeInstanceOf(DelimiterTextSplitter::class);
});

it('falls back to markdown-aware when driver name is unknown', function (): void {
    config()->set('ai.features.faq.splitter.driver', 'unknown_driver');

    expect(SplitterFactory::make())->toBeInstanceOf(MarkdownAwareSplitter::class);
});

it('forwards max_words and overlap_words to the markdown-aware splitter', function (): void {
    config()->set('ai.features.faq.splitter', [
        'driver' => 'markdown_aware',
        'max_words' => 80,
        'overlap_words' => 0,
        'prepend_heading_breadcrumb' => false,
    ]);

    $splitter = SplitterFactory::make();

    expect($splitter)->toBeInstanceOf(MarkdownAwareSplitter::class);

    $reflection = new ReflectionObject($splitter);
    expect($reflection->getProperty('maxWords')->getValue($splitter))->toBe(80);
    expect($reflection->getProperty('overlapWords')->getValue($splitter))->toBe(0);
    expect($reflection->getProperty('prependHeadingBreadcrumb')->getValue($splitter))->toBeFalse();
});
