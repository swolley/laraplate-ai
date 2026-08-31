<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Services\Assistance\Evaluation\AssistantEvaluationDataset;
use Modules\AI\Services\Assistance\Evaluation\AssistantEvaluationService;
use Modules\AI\Tests\Stubs\Assistance\ScriptedAssistantRunner;

uses(RefreshDatabase::class);

it('keeps the CMS assistant baseline at or above committed thresholds', function (): void {
    $path = base_path('Modules/CMS/docs/rag/evaluations/assistant-cms.json');
    $dataset = AssistantEvaluationDataset::fromFile($path);
    $runner = ScriptedAssistantRunner::bootstrap();

    $report = (new AssistantEvaluationService)->evaluate($dataset, 'level1', fn ($case) => $runner->run($case));

    expect($report['metrics']['citation_assembly'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['clarification_trigger_accuracy'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['abstention_accuracy'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['output_valid'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['unavailable_rate'])->toBe(0.0);
})->skip(fn (): bool => ! is_file(base_path('Modules/CMS/docs/rag/evaluations/assistant-cms.json')), 'CMS assistant dataset missing');
