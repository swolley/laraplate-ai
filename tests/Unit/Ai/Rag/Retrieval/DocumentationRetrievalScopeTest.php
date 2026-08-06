<?php

declare(strict_types=1);

use Modules\AI\Ai\Rag\Retrieval\DocumentationRetrievalContext;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Assistance\Scope\AssistantScope;
use Modules\AI\Services\Assistance\Scope\DataAccess;
use Modules\AI\Services\Assistance\Scope\DocScope;

beforeEach(function (): void {
    config()->set('ai.features.faq.max_documents', 5);
});

function scopeAccess(): AssistantAccessContext
{
    return new AssistantAccessContext(
        profile: AssistantProfile::InAppAssistance,
        userId: 'u1',
        tenantScope: AssistantTenantScope::Global,
        tenantId: null,
        locale: 'en',
        effectivePermissions: [],
        conversationId: 'c1',
    );
}

it('adds a module-or-cross-cutting clause under module scope', function (): void {
    $context = DocumentationRetrievalContext::fromAccessContextAndScope(
        scopeAccess(),
        new AssistantScope('erp', DataAccess::Module, DocScope::Module),
    );

    $filter = $context->elasticsearchFilter('in-app-docs-v1');
    $json = json_encode($filter);

    expect($context->moduleKey)->toBe('erp')
        ->and($json)->toContain('"metadata.module":"erp"')
        ->and($json)->toContain('metadata.cross_cutting_user');
});

it('omits the module clause under generic scope (backward compatible)', function (): void {
    $context = DocumentationRetrievalContext::fromAccessContextAndScope(
        scopeAccess(),
        AssistantScope::generic(),
    );

    $json = json_encode($context->elasticsearchFilter('in-app-docs-v1'));

    expect($context->moduleKey)->toBeNull()
        ->and($json)->not->toContain('metadata.module')
        ->and($json)->not->toContain('cross_cutting_user');
});

it('keeps fromAccessContext (no scope) module-agnostic', function (): void {
    $context = DocumentationRetrievalContext::fromAccessContext(scopeAccess());
    $json = json_encode($context->elasticsearchFilter('in-app-docs-v1'));
    expect($json)->not->toContain('metadata.module');
});
