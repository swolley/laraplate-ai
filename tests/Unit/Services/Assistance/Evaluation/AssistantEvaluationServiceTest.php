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
