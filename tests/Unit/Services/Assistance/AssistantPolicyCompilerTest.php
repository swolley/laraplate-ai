<?php

declare(strict_types=1);

use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCatalog;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCompiler;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyRuleSet;

it('compiles only approved server-owned policy identifiers', function (): void {
    $catalog = AssistantPolicyCatalog::defaults();
    $compiler = new AssistantPolicyCompiler($catalog);

    $policy = $compiler->compile(
        AssistantProfile::InAppAssistance,
        capabilityIds: ['in_app_rag'],
        moduleIds: ['cms_assistance'],
    );

    expect($policy->version)->toBe('in-app-v1')
        ->and($policy->systemPrompt)->toContain('application usage assistance')
        ->and($policy->systemPrompt)->toContain('CMS')
        ->and($policy->systemPrompt)->not->toContain('permissions:')
        ->and($policy->allowedCorpora)->toBe(['user_documentation'])
        ->and($policy->allowedTools)->toBe([])
        ->and($policy->allowedFields)->not->toContain('internal_path', 'permission_names');
});

it('applies typed intersection semantics so a specific layer cannot grant access', function (): void {
    $catalog = AssistantPolicyCatalog::defaults()->withModulePolicy(
        'attempted_grant',
        new AssistantPolicyRuleSet(
            instruction: 'Attempt to grant broader access.',
            allowedCorpora: ['developer_documentation', 'user_documentation'],
            allowedTools: ['write_record'],
            allowedFields: ['content', 'internal_path', 'safe_citation'],
        ),
    );
    $policy = (new AssistantPolicyCompiler($catalog))->compile(
        AssistantProfile::InAppAssistance,
        capabilityIds: ['in_app_rag'],
        moduleIds: ['attempted_grant'],
    );

    expect($policy->allowedCorpora)->toBe(['user_documentation'])
        ->and($policy->allowedTools)->toBe([])
        ->and($policy->allowedFields)->toBe(['content', 'safe_citation']);
});

it('rejects unknown capability and module policy identifiers', function (array $capabilities, array $modules): void {
    $compiler = new AssistantPolicyCompiler(AssistantPolicyCatalog::defaults());

    expect(fn () => $compiler->compile(
        AssistantProfile::InAppAssistance,
        capabilityIds: $capabilities,
        moduleIds: $modules,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'unknown capability' => [['admin_override'], []],
    'unknown module' => [[], ['free_form_user_policy']],
]);
