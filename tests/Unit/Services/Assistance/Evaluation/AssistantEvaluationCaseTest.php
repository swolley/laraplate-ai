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

it('carries the page a client reports and the proposals it expects', function (): void {
    $page = ['resource' => 'erp/orders', 'proposable' => []];
    $case = new AssistantEvaluationCase(
        id: 'p1',
        query: 'make lists easier',
        locale: 'en',
        moduleKey: null,
        expectedSurface: 'documentation',
        expectedCitations: [],
        expectClarification: false,
        expectRefusal: false,
        slices: ['proposal'],
        page: $page,
        expectedProposals: 1,
    );

    expect($case->page)->toBe($page)->and($case->expectedProposals)->toBe(1);
});

it('rejects expected proposals without a page, out of range, and a page that is not a bounded object', function (array $overrides): void {
    expect(fn () => new AssistantEvaluationCase(...[
        'id' => 'p1',
        'query' => 'q',
        'locale' => 'en',
        'moduleKey' => null,
        'expectedSurface' => 'documentation',
        'expectedCitations' => [],
        'expectClarification' => false,
        'expectRefusal' => false,
        'slices' => [],
        ...$overrides,
    ]))->toThrow(InvalidArgumentException::class);
})->with([
    'proposals without a page' => [['expectedProposals' => 1]],
    'more than a message holds' => [['page' => ['resource' => 'erp/orders'], 'expectedProposals' => 4]],
    'negative' => [['page' => ['resource' => 'erp/orders'], 'expectedProposals' => -1]],
    'an empty page' => [['page' => []]],
    'a list instead of an object' => [['page' => ['erp/orders']]],
    'no resource' => [['page' => ['proposable' => []]]],
    'too large' => [['page' => ['resource' => 'erp/orders', 'note' => str_repeat('x', 7000)]]],
]);
