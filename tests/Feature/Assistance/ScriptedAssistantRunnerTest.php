<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;
use Modules\AI\Services\Assistance\Evaluation\AssistantEvaluationCase;
use Modules\AI\Tests\Stubs\Assistance\ScriptedAssistantRunner;

uses(RefreshDatabase::class);

it('drives respond() to an application_content answer with the expected citation', function (): void {
    $runner = ScriptedAssistantRunner::bootstrap();
    $message = $runner->run(new AssistantEvaluationCase(
        id: 'hit',
        query: 'how do I publish?',
        locale: 'en',
        moduleKey: 'cms',
        expectedSurface: 'application_content',
        expectedCitations: ['Publishing guide'],
        expectClarification: false,
        expectRefusal: false,
        slices: ['publishing'],
    ));

    $labels = array_map(
        static fn (array $citation): string => $citation['label'],
        $message->metadata['citations'] ?? [],
    );

    expect($message->content)->toBe('Scripted application content answer for case [hit].')
        ->and($labels)->toContain('Publishing guide');
});

it('drives respond() to a refusal for a refuse case (empty evidence)', function (): void {
    $runner = ScriptedAssistantRunner::bootstrap();
    $message = $runner->run(new AssistantEvaluationCase(
        id: 'refuse',
        query: 'weather?',
        locale: 'en',
        moduleKey: 'cms',
        expectedSurface: 'refuse',
        expectedCitations: [],
        expectClarification: false,
        expectRefusal: true,
        slices: ['off_topic'],
    ));

    expect($message)->not->toBeNull()
        ->and($message->content)->toBe(AssistanceGuardrailPipeline::defaults()->insufficientEvidence('en'))
        ->and($message->metadata['citations'])->toBe([]);
});

it('drives respond() to a clarification for an ambiguous clarify case', function (): void {
    $runner = ScriptedAssistantRunner::bootstrap();
    $message = $runner->run(new AssistantEvaluationCase(
        id: 'clarify',
        query: 'How can I find the record?',
        locale: 'en',
        moduleKey: null,
        expectedSurface: 'clarify',
        expectedCitations: [],
        expectClarification: true,
        expectRefusal: false,
        slices: ['ambiguous'],
    ));

    expect($message->content)->toBe(AssistanceGuardrailPipeline::defaults()->clarificationRequired('en'));
});

it('drives respond() to a documentation answer grounded in the R0 documentation fixture', function (): void {
    $runner = ScriptedAssistantRunner::bootstrap();
    $message = $runner->run(new AssistantEvaluationCase(
        id: 'docs',
        query: 'how do I force a search term to be required?',
        locale: 'en',
        moduleKey: null,
        expectedSurface: 'documentation',
        expectedCitations: ['Core · Adaptive search matching · Required terms and exact phrases'],
        expectClarification: false,
        expectRefusal: false,
        slices: ['search'],
    ));

    $labels = array_map(
        static fn (array $citation): string => $citation['label'],
        $message->metadata['citations'] ?? [],
    );

    expect($message->content)->toBe('Scripted documentation answer for case [docs].')
        ->and($labels)->toContain('Core · Adaptive search matching · Required terms and exact phrases');
});

it('drives respond() to a scripted graph answer with no application tool offered', function (): void {
    $runner = ScriptedAssistantRunner::bootstrap();
    $message = $runner->run(new AssistantEvaluationCase(
        id: 'graph',
        query: 'Find related CMS content.',
        locale: 'en',
        moduleKey: null,
        expectedSurface: 'graph',
        expectedCitations: [],
        expectClarification: false,
        expectRefusal: false,
        slices: ['relations'],
    ));

    expect($message->content)->toBe('Scripted graph answer for case [graph].')
        ->and($message->metadata['citations'])->toBe([]);
});

it('reuses one bootstrapped runner across several cases without leaking state between them', function (): void {
    $runner = ScriptedAssistantRunner::bootstrap();

    $hit = $runner->run(new AssistantEvaluationCase(
        id: 'hit-2',
        query: 'how do I publish?',
        locale: 'en',
        moduleKey: 'cms',
        expectedSurface: 'application_content',
        expectedCitations: ['Publishing guide'],
        expectClarification: false,
        expectRefusal: false,
        slices: ['publishing'],
    ));

    $clarify = $runner->run(new AssistantEvaluationCase(
        id: 'clarify-2',
        query: 'How can I find the record?',
        locale: 'en',
        moduleKey: null,
        expectedSurface: 'clarify',
        expectedCitations: [],
        expectClarification: true,
        expectRefusal: false,
        slices: ['ambiguous'],
    ));

    expect($hit->metadata['citations'])->toHaveCount(1)
        ->and($clarify->content)->toBe(AssistanceGuardrailPipeline::defaults()->clarificationRequired('en'))
        ->and($clarify->metadata['citations'] ?? [])->toBe([]);
});
