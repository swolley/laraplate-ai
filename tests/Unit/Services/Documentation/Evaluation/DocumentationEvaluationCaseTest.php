<?php

declare(strict_types=1);

use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationCase;

function makeDocCase(array $overrides = []): DocumentationEvaluationCase
{
    return new DocumentationEvaluationCase(
        id: $overrides['id'] ?? 'exact',
        query: $overrides['query'] ?? 'how do I export a grid?',
        locale: $overrides['locale'] ?? 'en',
        topK: $overrides['topK'] ?? 5,
        expectedSourceLabels: $overrides['expectedSourceLabels'] ?? ['Core · Grid export'],
        expectedCitationLabels: $overrides['expectedCitationLabels'] ?? ['Core · Grid export'],
        expectAuthorizedEmpty: $overrides['expectAuthorizedEmpty'] ?? false,
        expectSupportedAnswer: $overrides['expectSupportedAnswer'] ?? true,
        expectRefusal: $overrides['expectRefusal'] ?? false,
        slices: $overrides['slices'] ?? ['grid', 'single_hop'],
        tenantScope: $overrides['tenantScope'] ?? AssistantTenantScope::Global,
        tenantId: $overrides['tenantId'] ?? null,
        effectivePermissions: $overrides['effectivePermissions'] ?? [],
    );
}

it('builds a valid case and an in-app access context', function (): void {
    $case = makeDocCase();
    $access = $case->accessContext();

    expect($access->profile)->toBe(AssistantProfile::InAppAssistance)
        ->and($access->tenantScope)->toBe(AssistantTenantScope::Global)
        ->and($access->tenantId)->toBeNull()
        ->and($access->locale)->toBe('en');
});

it('rejects a refusal case that still expects sources', function (): void {
    expect(fn () => makeDocCase(['expectRefusal' => true, 'expectSupportedAnswer' => false]))
        ->toThrow(InvalidArgumentException::class);
})->with([[['expectedSourceLabels' => ['x'], 'expectedCitationLabels' => []]]]);

it('rejects an authorized-empty case that expects sources', function (): void {
    expect(fn () => makeDocCase([
        'expectAuthorizedEmpty' => true,
        'expectedSourceLabels' => ['x'],
        'expectedCitationLabels' => [],
        'expectSupportedAnswer' => false,
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects contradictory supported + refusal flags', function (): void {
    expect(fn () => makeDocCase(['expectSupportedAnswer' => true, 'expectRefusal' => true]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a Tenant scope without a tenant id', function (): void {
    expect(fn () => makeDocCase(['tenantScope' => AssistantTenantScope::Tenant, 'tenantId' => null]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a bad locale and a bad slice slug', function (): void {
    expect(fn () => makeDocCase(['locale' => 'english']))->toThrow(InvalidArgumentException::class);
    expect(fn () => makeDocCase(['slices' => ['Not A Slug']]))->toThrow(InvalidArgumentException::class);
});
