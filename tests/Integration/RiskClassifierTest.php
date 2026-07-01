<?php

declare(strict_types=1);

use Modules\AI\Services\Tools\RiskClassifier;

it('returns explicit config_risk when provided', function (): void {
    $classifier = new RiskClassifier;

    expect($classifier->classifyRisk('some_tool', [], 'high'))->toBe('high');
    expect($classifier->classifyRisk('some_tool', [], 'medium'))->toBe('medium');
    expect($classifier->classifyRisk('some_tool', [], 'low'))->toBe('low');
    expect($classifier->classifyRisk('some_tool', [], 'unknown'))->toBe('unknown');
});

it('ignores invalid explicit config_risk and falls through', function (): void {
    $classifier = new RiskClassifier;

    expect($classifier->classifyRisk('get_info', [], 'invalid'))->toBe('low');
});

it('uses tool_definitions config when no explicit risk provided', function (): void {
    $classifier = new RiskClassifier([
        'my_tool' => ['risk_level' => 'high'],
    ]);

    expect($classifier->classifyRisk('my_tool', []))->toBe('high');
});

it('falls back to empty definitions when configured definitions are not an array', function (): void {
    config()->set('ai.features.tools.definitions', 'invalid');

    $classifier = new RiskClassifier;

    expect($classifier->classifyRisk('get_info', []))->toBe('low');
});

it('falls back to heuristic when no config match', function (): void {
    $classifier = new RiskClassifier([]);

    expect($classifier->classifyRisk('get_user_info', []))->toBe('low');
});

it('classifies delete tools as high risk by heuristic', function (): void {
    $classifier = new RiskClassifier([]);

    expect($classifier->classifyRisk('delete_record', []))->toBe('high');
    expect($classifier->classifyRisk('remove_user', []))->toBe('high');
    expect($classifier->classifyRisk('destroy_session', []))->toBe('high');
});

it('classifies mutation tools as medium risk by heuristic', function (): void {
    $classifier = new RiskClassifier([]);

    expect($classifier->classifyRisk('update_profile', []))->toBe('medium');
    expect($classifier->classifyRisk('edit_document', []))->toBe('medium');
    expect($classifier->classifyRisk('create_user', []))->toBe('medium');
    expect($classifier->classifyRisk('insert_record', []))->toBe('medium');
});

it('classifies read-only tools as low risk by heuristic', function (): void {
    $classifier = new RiskClassifier([]);

    expect($classifier->classifyRisk('get_weather', []))->toBe('low');
    expect($classifier->classifyRisk('search_documents', []))->toBe('low');
    expect($classifier->classifyRisk('list_users', []))->toBe('low');
});

it('prioritizes explicit config over definitions config', function (): void {
    $classifier = new RiskClassifier([
        'my_tool' => ['risk_level' => 'low'],
    ]);

    expect($classifier->classifyRisk('my_tool', [], 'high'))->toBe('high');
});

it('prioritizes definitions config over heuristic', function (): void {
    $classifier = new RiskClassifier([
        'delete_stuff' => ['risk_level' => 'low'],
    ]);

    expect($classifier->classifyRisk('delete_stuff', []))->toBe('low');
});
