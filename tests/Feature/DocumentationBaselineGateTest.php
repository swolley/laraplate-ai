<?php

declare(strict_types=1);

use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationDataset;
use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationService;
use Modules\AI\Tests\Stubs\Documentation\CoreUserDocumentationCorpus;

beforeEach(function (): void {
    config()->set('ai.features.faq.max_documents', 5);
    config()->set('ai.features.faq.policy_classification_version', 'in-app-docs-v1');
});

it('keeps the Core/user documentation baseline at or above committed thresholds', function (): void {
    $path = base_path('Modules/Core/docs/rag/evaluations/2026-08-documentation-user.json');
    $dataset = DocumentationEvaluationDataset::fromFile($path);
    $retrieval = CoreUserDocumentationCorpus::retrieval();

    $report = (new DocumentationEvaluationService)->evaluate(
        $dataset,
        'fixture',
        static fn (string $q, $access): array => $retrieval->retrieve($q, $access),
    );

    // Committed baseline thresholds (2026-08). Raise only with an intentional, reviewed commit.
    expect($report['metrics']['source_hit_at_k'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['mean_reciprocal_rank'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['citation_precision'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['refusal_accuracy'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['supported_answer_rate'])->toBeGreaterThanOrEqual(1.0);
})->skip(fn (): bool => ! is_file(base_path('Modules/Core/docs/rag/evaluations/2026-08-documentation-user.json')), 'Core dataset missing');
