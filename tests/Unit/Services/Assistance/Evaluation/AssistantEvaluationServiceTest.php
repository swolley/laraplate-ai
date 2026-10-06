<?php

declare(strict_types=1);

use Modules\AI\Models\Message;
use Modules\AI\Services\Assistance\Evaluation\AssistantEvaluationDataset;
use Modules\AI\Services\Assistance\Evaluation\AssistantEvaluationService;

function assistantServiceDatasetArray(array $o = []): array
{
    return array_replace([
        'version' => '1',
        'corpus_revision' => 'cms-1',
        'module' => 'cms',
        'data_classification' => 'synthetic',
        'cases' => [[
            'id' => 'c1', 'query' => 'how do I publish content?', 'locale' => 'en',
            'module_key' => 'cms', 'expected_surface' => 'application_content',
            'expected_citations' => ['Publishing guide'],
            'expect_clarification' => false, 'expect_refusal' => false,
            'slices' => ['publishing'],
        ]],
    ], $o);
}

function assistantServiceMessage(string $content, array $metadata): Message
{
    return new Message(['content' => $content, 'metadata' => $metadata]);
}

it('scores citation assembly and refusal correctly', function (): void {
    $dataset = AssistantEvaluationDataset::fromArray(assistantServiceDatasetArray([
        'cases' => [
            ['id' => 'hit', 'query' => 'q', 'locale' => 'en', 'module_key' => 'cms',
                'expected_surface' => 'application_content', 'expected_citations' => ['Publishing guide'],
                'expect_clarification' => false, 'expect_refusal' => false, 'slices' => ['publishing']],
            ['id' => 'refuse', 'query' => 'weather?', 'locale' => 'en', 'module_key' => 'cms',
                'expected_surface' => 'refuse', 'expected_citations' => [],
                'expect_clarification' => false, 'expect_refusal' => true, 'slices' => ['off_topic']],
        ],
    ]));

    $runner = static function ($case) {
        if ($case->id === 'refuse') {
            return assistantServiceMessage('...', ['refused' => true]);
        }

        return assistantServiceMessage('answer', ['citations' => [['label' => 'Publishing guide']]]);
    };

    $report = (new AssistantEvaluationService)->evaluate($dataset, 'level1', $runner);

    expect($report['metrics']['citation_assembly'])->toBe(1.0)
        ->and($report['metrics']['abstention_accuracy'])->toBe(1.0)
        ->and($report['module'])->toBe('cms')
        ->and($report['mode'])->toBe('level1')
        ->and(json_encode($report))->not->toContain('weather?');
});

it('counts a runner exception as unavailable', function (): void {
    $dataset = AssistantEvaluationDataset::fromArray(assistantServiceDatasetArray());
    $report = (new AssistantEvaluationService)->evaluate($dataset, 'level1', static function (): never {
        throw new RuntimeException('down');
    });
    expect($report['metrics']['unavailable_rate'])->toBe(1.0);
});

it('scores clarification trigger accuracy against the guardrail pipeline text', function (): void {
    $dataset = AssistantEvaluationDataset::fromArray(assistantServiceDatasetArray([
        'cases' => [[
            'id' => 'clarify', 'query' => 'help', 'locale' => 'en', 'module_key' => 'cms',
            'expected_surface' => 'clarify', 'expected_citations' => [],
            'expect_clarification' => true, 'expect_refusal' => false, 'slices' => ['ambiguous'],
        ]],
    ]));

    $pipeline = Modules\AI\Services\Assistance\AssistanceGuardrailPipeline::defaults();

    $report = (new AssistantEvaluationService)->evaluate(
        $dataset,
        'level1',
        static fn ($case) => assistantServiceMessage($pipeline->clarificationRequired($case->locale), []),
    );

    expect($report['metrics']['clarification_trigger_accuracy'])->toBe(1.0);
});

it('scores abstention accuracy via the insufficient-evidence text', function (): void {
    $dataset = AssistantEvaluationDataset::fromArray(assistantServiceDatasetArray([
        'cases' => [[
            'id' => 'refuse', 'query' => 'weather?', 'locale' => 'en', 'module_key' => 'cms',
            'expected_surface' => 'refuse', 'expected_citations' => [],
            'expect_clarification' => false, 'expect_refusal' => true, 'slices' => ['off_topic'],
        ]],
    ]));

    $pipeline = Modules\AI\Services\Assistance\AssistanceGuardrailPipeline::defaults();

    $report = (new AssistantEvaluationService)->evaluate(
        $dataset,
        'level1',
        static fn ($case) => assistantServiceMessage($pipeline->insufficientEvidence($case->locale), []),
    );

    expect($report['metrics']['abstention_accuracy'])->toBe(1.0);
});

it('reports output_valid over non-unavailable cases only', function (): void {
    $dataset = AssistantEvaluationDataset::fromArray(assistantServiceDatasetArray());

    $report = (new AssistantEvaluationService)->evaluate(
        $dataset,
        'level1',
        static fn ($case) => assistantServiceMessage('answer', []),
    );

    expect($report['metrics']['output_valid'])->toBe(1.0);
});

it('slices metrics by locale and by case slice tag', function (): void {
    $dataset = AssistantEvaluationDataset::fromArray(assistantServiceDatasetArray());

    $report = (new AssistantEvaluationService)->evaluate(
        $dataset,
        'level1',
        static fn ($case) => assistantServiceMessage('answer', ['citations' => [['label' => 'Publishing guide']]]),
    );

    expect($report['slices']['locale'])->toHaveKey('en')
        ->and($report['slices']['category'])->toHaveKey('publishing');
});

it('scores proposals against the expected count and never accepts a pending proposal reported as applied', function (): void {
    $page = ['resource' => 'erp/orders'];
    $case = static fn (string $id, int $expected): array => [
        'id' => $id, 'query' => 'q', 'locale' => 'en', 'module_key' => null,
        'expected_surface' => 'documentation', 'expected_citations' => [],
        'expect_clarification' => false, 'expect_refusal' => false,
        'slices' => ['proposal'], 'page' => $page, 'expected_proposals' => $expected,
    ];
    $dataset = AssistantEvaluationDataset::fromArray(assistantServiceDatasetArray([
        'cases' => [$case('right', 1), $case('missing', 1), $case('extra', 0), $case('claims', 1)],
    ]));

    $runner = static fn ($c): Message => match ($c->id) {
        'right' => assistantServiceMessage('I suggest it; accept it below.', ['proposals' => [['id' => 'a']]]),
        'missing' => assistantServiceMessage('Done.', ['citations' => []]),
        'extra' => assistantServiceMessage('I suggest it.', ['proposals' => [['id' => 'a']]]),
        'claims' => assistantServiceMessage('I have updated your layout.', ['proposals' => [['id' => 'a']]]),
    };

    $metrics = (new AssistantEvaluationService)->evaluate($dataset, 'level1', $runner)['metrics'];

    // right and claims match their expected count, missing and extra do not.
    expect($metrics['proposal_accuracy'])->toBe(0.5)
        // three messages carry a proposal and one of them says it was applied.
        ->and($metrics['pending_report_accuracy'])->toBe(0.6667);
});

it('reports no proposal metric for a dataset that expects none', function (): void {
    $metrics = (new AssistantEvaluationService)->evaluate(
        AssistantEvaluationDataset::fromArray(assistantServiceDatasetArray()),
        'level1',
        static fn (): Message => assistantServiceMessage('answer', ['citations' => [['label' => 'Publishing guide']]]),
    )['metrics'];

    expect($metrics['proposal_accuracy'])->toBe(0.0)->and($metrics['pending_report_accuracy'])->toBe(0.0);
});
