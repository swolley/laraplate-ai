<?php

declare(strict_types=1);

use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Services\Assistance\AssistantCapabilities;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCatalog;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCompiler;

function compiledAssistantPolicy(AssistantProfile $profile, array $capabilities): Modules\AI\Services\Assistance\Policies\CompiledAssistantPolicy
{
    return new AssistantPolicyCompiler(AssistantPolicyCatalog::defaults())->compile($profile, $capabilities);
}

it('admits the write tools only for a profile that lists the capability', function (): void {
    $with = compiledAssistantPolicy(AssistantProfile::InAppAssistance, [AssistantCapabilities::WRITES_CAPABILITY]);
    $without = compiledAssistantPolicy(AssistantProfile::InAppAssistance, ['in_app_rag', 'read_only_graph', 'application_content', AssistantCapabilities::PROPOSALS_CAPABILITY]);

    foreach (['crud_create_cms_content', 'crud_update_cms_content', 'crud_delete_cms_content', 'crud_bulk_update_cms_content', 'crud_bulk_delete_cms_content'] as $tool) {
        expect($with->allowsTool($tool))->toBeTrue($tool)
            ->and($without->allowsTool($tool))->toBeFalse($tool);
    }
});

it('never admits a decision on a pending approval, whatever capability is asked for', function (): void {
    $policy = compiledAssistantPolicy(AssistantProfile::InAppAssistance, [AssistantCapabilities::READS_CAPABILITY, AssistantCapabilities::WRITES_CAPABILITY]);

    expect($policy->allowsTool('crud_approve_cms_content'))->toBeFalse()
        ->and($policy->allowsTool('crud_disapprove_cms_content'))->toBeFalse()
        ->and($policy->allowsTool('crud_pending_approvals_cms_content'))->toBeTrue();
});

it('keeps reads and writes apart: opening one never opens the other', function (): void {
    $reads = compiledAssistantPolicy(AssistantProfile::InAppAssistance, [AssistantCapabilities::READS_CAPABILITY]);
    $writes = compiledAssistantPolicy(AssistantProfile::InAppAssistance, [AssistantCapabilities::WRITES_CAPABILITY]);

    expect($reads->allowsTool('crud_list_cms_content'))->toBeTrue()
        ->and($reads->allowsTool('crud_update_cms_content'))->toBeFalse()
        ->and($writes->allowsTool('crud_update_cms_content'))->toBeTrue()
        ->and($writes->allowsTool('crud_list_cms_content'))->toBeFalse();
});

it('does not let developer help receive the capability or any crud tool', function (): void {
    $compiler = new AssistantPolicyCompiler(AssistantPolicyCatalog::defaults());
    $policy = $compiler->compile(AssistantProfile::DeveloperHelp);

    expect(fn () => $compiler->compile(AssistantProfile::DeveloperHelp, [AssistantCapabilities::WRITES_CAPABILITY]))->toThrow(InvalidArgumentException::class)
        ->and($policy->allowedTools)->toBe([])
        ->and($policy->allowsTool('crud_update_cms_content'))->toBeFalse();
});

it('reports writes as a feature only when an entity is opted into a write operation', function (array $entities, bool $expected): void {
    config(['ai.features.tools.crud.entities' => $entities]);

    expect(app(AssistantCapabilities::class)->writes())->toBe($expected);
})->with([
    'nothing opted in' => [[], false],
    'reads only' => [['cms.content' => ['list', 'detail']], false],
    'a write operation' => [['cms.content' => ['list', 'update']], true],
    'a bulk operation' => [['cms.content' => ['bulk_delete']], true],
]);
