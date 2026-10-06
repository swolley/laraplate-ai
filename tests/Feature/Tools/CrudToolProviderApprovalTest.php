<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Tools\CrudToolProvider;
use Modules\AI\Services\Tools\ToolDefinition;
use Modules\Core\Models\DynamicEntity;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Setting;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Export\TabularCsvExporter;
use Modules\Core\Services\Export\TabularPdfExporter;
use Modules\Core\Support\PermissionName;
use Modules\Core\Tests\Support\HttpContext;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);

    foreach (['select', 'update'] as $ability) {
        $name = PermissionName::forModel(DynamicEntity::resolve('setting', module: 'core'), $ability);
        Permission::findOrCreate($name, 'web');
        $user->givePermissionTo($name);
    }

    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['update']]);
    $this->setting = Setting::factory()->persistedWithoutApprovalCapture()->create(['type' => 'string', 'value' => 'x', 'choices' => null]);
    HttpContext::pretendHttpRequest();

    $request = Request::create('/app/ai/assist', 'POST');
    $request->setUserResolver(fn (): object => $user);
    $provider = new CrudToolProvider(
        resolve(CrudService::class),
        resolve(AuthorizationService::class),
        $request,
        resolve(TabularCsvExporter::class),
        resolve(TabularPdfExporter::class),
        resolve(Modules\AI\Services\Assistance\Writes\WriteProposalService::class),
    );
    $this->conversation = Modules\AI\Models\Conversation::query()->create(['user_id' => $user->getKey()]);
    $context = new AssistantAccessContext(AssistantProfile::InAppAssistance, (string) $user->getAuthIdentifier(), AssistantTenantScope::Global, null, 'en', [], (string) $this->conversation->getKey());
    $this->provider = $provider;
    $this->user = $user;

    $this->update = collect($provider->tools($context))->first(static fn (ToolDefinition $tool): bool => $tool->name === 'crud_update_core_setting');
});

it('proposes an update and, once the person confirms, reports it as sent for approval instead of applied', function (): void {
    $proposal = ($this->update->handler)(id: (string) $this->setting->id, attributes: ['is_public' => true]);

    expect($proposal['status'])->toBe('awaiting_confirmation')
        ->and($proposal['requires_approval'])->toBeTrue()
        ->and($this->setting->fresh()->is_public)->toBeFalse();

    $stored = Modules\AI\Models\WriteProposal::query()->findOrFail($proposal['proposal_id']);
    $resolved = resolve(Modules\AI\Services\Assistance\Writes\WriteProposalService::class)
        ->confirm($stored, $this->user, $this->provider->applyProposal(...));

    expect($resolved->status)->toBe(Modules\AI\Enums\WriteProposalStatus::PendingApproval)
        ->and($resolved->outcome['operation'])->toBe('update')
        ->and($resolved->outcome['modification'])->toBeInt()
        ->and($this->setting->fresh()->is_public)->toBeFalse();
});
