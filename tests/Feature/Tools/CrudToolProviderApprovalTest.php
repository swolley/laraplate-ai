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
    );
    $context = new AssistantAccessContext(AssistantProfile::InAppAssistance, (string) $user->getAuthIdentifier(), AssistantTenantScope::Global, null, 'en', [], 'conv-test');

    $this->update = collect($provider->tools($context))->first(static fn (ToolDefinition $tool): bool => $tool->name === 'crud_update_core_setting');
});

it('reports an update sent for approval instead of the updated record', function (): void {
    $result = ($this->update->handler)(id: (string) $this->setting->id, attributes: ['is_public' => true]);

    expect($result['status'])->toBe('pending_approval')
        ->and($result['operation'])->toBe('update')
        ->and($result['modification'])->toBeInt()
        ->and($result)->not->toHaveKey('data')
        ->and($this->setting->fresh()->is_public)->toBeFalse();
});
