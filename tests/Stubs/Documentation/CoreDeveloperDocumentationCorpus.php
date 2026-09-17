<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Documentation;

use Modules\AI\Ai\Rag\Retrieval\DeveloperDocumentationRetrieval;

/**
 * Deterministic developer-corpus fixture aligned with
 * `Modules/AI/docs/rag/evaluations/2026-09-17-developer-core-baseline.json`.
 *
 * Each supported query maps to the single document whose `sourceName` equals
 * that case's `expected_source_labels`; unsupported queries map to `[]`. It
 * feeds the plumbing/alignment gate — real-Elasticsearch vector-only quality
 * lives in the committed baseline report, not here.
 */
final class CoreDeveloperDocumentationCorpus
{
    public static function retrieval(): DeveloperDocumentationRetrieval
    {
        $doc = static fn (string $label): array => [
            FakeDocumentationSearch::document($label, 'en', 'Developer reference for ' . $label . '.', ['Core']),
        ];

        return FakeDocumentationSearch::forDeveloperRetrieval([
            'What is CannotUnlockException and when is it thrown?' => $doc('faq-module-Core/RECORD_LOCKING_DEVELOPER.md'),
            'As a developer, how do I programmatically acquire and release a record lock?' => $doc('faq-module-Core/RECORD_LOCKING_DEVELOPER.md'),
            'What does the DatabaseTextMatchCompiler do?' => $doc('faq-module-Core/SEARCH_MATCHING_DEVELOPER.md'),
            'How is developer-facing search matching implemented across database drivers?' => $doc('faq-module-Core/SEARCH_MATCHING_DEVELOPER.md'),
            'How do I extend AbstractImportCommand to add a new interactive import?' => $doc('faq-module-Core/INTERACTIVE_IMPORT_DEVELOPER.md'),
            'perf:crud' => $doc('faq-module-Core/PERFORMANCE_TOOLKIT.md'),
            'IReranker' => $doc('faq-module-Core/SEARCH_RETRIEVAL_PIPELINE.md'),
            'EnsembleSearchService' => $doc('faq-module-Core/SEARCH_RETRIEVAL_PIPELINE.md'),
            'How does event orchestration work in the Core module?' => $doc('faq-module-Core/EVENT_ORCHESTRATION.md'),
            'How are in-app notifications delivered to users?' => $doc('faq-module-Core/NOTIFICATIONS.md'),
            'How does the search retrieval pipeline rank and rerank results?' => $doc('faq-module-Core/SEARCH_RETRIEVAL_PIPELINE.md'),
            'How do I configure Stripe billing webhooks?' => [],
            'How do I enable GraphQL schema stitching for the public API?' => [],
        ]);
    }
}
