<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Chunking;

use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use NeuronAI\RAG\Splitter\SentenceTextSplitter;
use NeuronAI\RAG\Splitter\SplitterInterface;

/**
 * Resolves the configured splitter implementation for documentation indexing.
 *
 * Reads from `ai.features.faq.splitter.*` and produces a ready-to-use
 * {@see SplitterInterface}. Defaults to {@see MarkdownAwareSplitter} so that
 * Mermaid diagrams and code/table blocks are preserved as atomic units.
 */
final class SplitterFactory
{
    /**
     * @var array<int, string>
     */
    private const array SUPPORTED_DRIVERS = ['markdown_aware', 'sentence', 'delimiter'];

    public static function make(): SplitterInterface
    {
        $driver = self::resolveDriver();
        $max_words = self::positiveIntConfig('ai.features.faq.splitter.max_words', 250);
        $overlap_words = max(0, (int) config('ai.features.faq.splitter.overlap_words', 0));
        $prepend_breadcrumb = (bool) config('ai.features.faq.splitter.prepend_heading_breadcrumb', true);

        if ($overlap_words >= $max_words) {
            $overlap_words = 0;
        }

        return match ($driver) {
            'sentence' => new SentenceTextSplitter($max_words, $overlap_words),
            'delimiter' => new DelimiterTextSplitter(maxLength: $max_words * 6, wordOverlap: $overlap_words),
            default => new MarkdownAwareSplitter($max_words, $overlap_words, $prepend_breadcrumb),
        };
    }

    private static function resolveDriver(): string
    {
        $driver = (string) config('ai.features.faq.splitter.driver', 'markdown_aware');

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            return 'markdown_aware';
        }

        return $driver;
    }

    private static function positiveIntConfig(string $key, int $default): int
    {
        $value = (int) config($key, $default);

        return $value > 0 ? $value : $default;
    }
}
