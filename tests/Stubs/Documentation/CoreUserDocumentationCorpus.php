<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Documentation;

use Modules\AI\Ai\Rag\Retrieval\InAppDocumentationRetrieval;

final class CoreUserDocumentationCorpus
{
    public static function retrieval(): InAppDocumentationRetrieval
    {
        $doc = static fn (string $label, array $breadcrumb): array => [
            FakeDocumentationSearch::document($label, 'en', 'Reference content for ' . $label . '.', $breadcrumb),
        ];

        return FakeDocumentationSearch::forInAppRetrieval([
            'how do I force a search term to be required?' => $doc(
                'Core · Adaptive search matching · Required terms and exact phrases',
                ['Core', 'Adaptive search matching', 'Required terms and exact phrases'],
            ),
            'what matching preferences can I set for search?' => $doc(
                'Core · Adaptive search matching · Matching preferences',
                ['Core', 'Adaptive search matching', 'Matching preferences'],
            ),
            'what is the difference between a permission and an ACL?' => $doc(
                'Core · Glossary · Permission vs ACL',
                ['Core', 'Glossary', 'Permission vs ACL'],
            ),
            'how does the cross-module event bus order listeners?' => $doc(
                'Core · Event orchestration',
                ['Core', 'Event orchestration'],
            ),
            'what is the capital of France?' => [],
        ]);
    }
}
