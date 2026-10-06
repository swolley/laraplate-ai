<?php

declare(strict_types=1);

use Modules\AI\Services\Assistance\Policies\AssistantPolicyCatalog;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCompiler;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyRuleSet;
use Modules\Core\Models\User;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * A catalog where the profile of the in-app assistant is granted the capability `ui_proposals`.
 */
function catalogGrantingProposals(): AssistantPolicyCatalog
{
    $defaults = AssistantPolicyCatalog::defaults();
    $profile = $defaults->profiles['in_app_assistance'];

    return new AssistantPolicyCatalog(
        version: $defaults->version,
        globalPolicy: $defaults->globalPolicy,
        profiles: [
            ...$defaults->profiles,
            'in_app_assistance' => new AssistantPolicyRuleSet(
                instruction: $profile->instruction,
                allowedCorpora: $profile->allowedCorpora,
                allowedTools: [...$profile->allowedTools, 'propose_preference_change', 'propose_view_state'],
                allowedFields: $profile->allowedFields,
                deniedCorpora: $profile->deniedCorpora,
                deniedTools: $profile->deniedTools,
                deniedFields: $profile->deniedFields,
            ),
        ],
        capabilities: [
            ...$defaults->capabilities,
            'ui_proposals' => new AssistantPolicyRuleSet(
                instruction: 'Propose changes the user confirms.',
                allowedCorpora: [],
                allowedTools: ['propose_preference_change', 'propose_view_state'],
                allowedFields: [],
            ),
        ],
        modules: $defaults->modules,
    );
}

function useCatalog(AssistantPolicyCatalog $catalog): void
{
    app()->instance(AssistantPolicyCatalog::class, $catalog);
    app()->instance(AssistantPolicyCompiler::class, new AssistantPolicyCompiler($catalog));
}

beforeEach(function (): void {
    $this->user = User::factory()->create();
    config([
        'ai.features.faq.enabled' => true,
        'ai.features.chat.model' => 'ollama:llama3.2:3b',
        'ai.providers.ollama.api_url' => 'http://localhost:11434',
    ]);
});

it('tells a signed-in user what the assistant offers', function (): void {
    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertOk()
        ->assertJsonPath('data', ['enabled' => true, 'configured' => true, 'features' => ['proposals' => false, 'streaming' => false]]);
});

it('is not enabled while the assistant feature is off', function (): void {
    config(['ai.features.faq.enabled' => false]);

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.configured', true);
});

it('is not configured while the chat provider lacks what it needs', function (string $model, array $settings): void {
    config(['ai.features.chat.model' => $model, ...$settings]);

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.configured', false);
})->with([
    'ollama without a url' => ['ollama:llama3.2:3b', ['ai.providers.ollama.api_url' => '']],
    'openai without a key' => ['openai:gpt-4o-mini', ['ai.providers.openai.api_key' => '']],
    'an unsupported provider' => ['nowhere:model', []],
]);

it('is configured when the chat provider holds a key', function (): void {
    config(['ai.features.chat.model' => 'openai:gpt-4o-mini', 'ai.providers.openai.api_key' => 'sk-test-not-real']);

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertJsonPath('data.configured', true);
});

it('offers proposals when the compiled policy of the in-app profile holds ui_proposals', function (): void {
    useCatalog(catalogGrantingProposals());

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertJsonPath('data.features.proposals', true);
});

it('does not offer proposals when the capability exists but the profile does not grant it', function (): void {
    $defaults = AssistantPolicyCatalog::defaults();
    $granting = catalogGrantingProposals();

    useCatalog(new AssistantPolicyCatalog(
        version: $defaults->version,
        globalPolicy: $defaults->globalPolicy,
        profiles: $defaults->profiles,
        capabilities: $granting->capabilities,
        modules: $defaults->modules,
    ));

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertJsonPath('data.features.proposals', false);
});

it('refuses a request that is not signed in', function (): void {
    $this->getJson(route('ai.capabilities'))->assertUnauthorized();
});

it('refuses the guest account', function (): void {
    $guest = User::factory()->create(['name' => config('permission.users.guest'), 'username' => config('permission.users.guest')]);

    $this->actingAs($guest)->getJson(route('ai.capabilities'))->assertForbidden();
});

it('answers the same whatever the query string says', function (): void {
    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities', ['enabled' => 'false', 'features' => ['proposals' => true]]))
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.features.proposals', false);
});
