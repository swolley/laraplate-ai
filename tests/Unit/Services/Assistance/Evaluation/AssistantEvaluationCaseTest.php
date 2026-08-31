<?php

declare(strict_types=1);

use Modules\AI\Services\Assistance\Evaluation\AssistantEvaluationCase;

function makeAssistantCase(array $o = []): AssistantEvaluationCase
{
    return new AssistantEvaluationCase(
        id: $o['id'] ?? 'c1',
        query: $o['query'] ?? 'how do I publish content?',
        locale: $o['locale'] ?? 'en',
        moduleKey: array_key_exists('moduleKey', $o) ? $o['moduleKey'] : 'cms',
        expectedSurface: $o['expectedSurface'] ?? 'application_content',
        expectedCitations: $o['expectedCitations'] ?? ['Publishing guide'],
        expectClarification: $o['expectClarification'] ?? false,
        expectRefusal: $o['expectRefusal'] ?? false,
        slices: $o['slices'] ?? ['publishing', 'single_hop'],
    );
}

it('builds a valid application_content case', function (): void {
    $c = makeAssistantCase();
    expect($c->expectedSurface)->toBe('application_content')->and($c->moduleKey)->toBe('cms');
});

it('allows a null moduleKey (generic scope)', function (): void {
    expect(makeAssistantCase(['moduleKey' => null, 'expectedSurface' => 'documentation'])->moduleKey)->toBeNull();
});

it('rejects an unknown surface', function (): void {
    expect(fn () => makeAssistantCase(['expectedSurface' => 'sql']))->toThrow(InvalidArgumentException::class);
});

it('requires clarify surface to set expectClarification and carry no citations', function (): void {
    expect(fn () => makeAssistantCase(['expectedSurface' => 'clarify', 'expectClarification' => false]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => makeAssistantCase(['expectedSurface' => 'clarify', 'expectClarification' => true, 'expectedCitations' => ['x']]))
        ->toThrow(InvalidArgumentException::class);
    expect(makeAssistantCase(['expectedSurface' => 'clarify', 'expectClarification' => true, 'expectedCitations' => []])->expectClarification)->toBeTrue();
});

it('requires refuse surface to set expectRefusal and carry no citations', function (): void {
    expect(fn () => makeAssistantCase(['expectedSurface' => 'refuse', 'expectRefusal' => false]))
        ->toThrow(InvalidArgumentException::class);
    expect(makeAssistantCase(['expectedSurface' => 'refuse', 'expectRefusal' => true, 'expectedCitations' => []])->expectRefusal)->toBeTrue();
});

it('rejects a malformed id, locale, module key, or slice slug', function (): void {
    expect(fn () => makeAssistantCase(['id' => 'Bad Id']))->toThrow(InvalidArgumentException::class);
    expect(fn () => makeAssistantCase(['locale' => 'english']))->toThrow(InvalidArgumentException::class);
    expect(fn () => makeAssistantCase(['moduleKey' => 'Bad Mod']))->toThrow(InvalidArgumentException::class);
    expect(fn () => makeAssistantCase(['slices' => ['Not A Slug']]))->toThrow(InvalidArgumentException::class);
});
