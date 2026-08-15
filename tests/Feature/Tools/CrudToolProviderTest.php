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
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Support\PermissionName;

uses(RefreshDatabase::class);

function makeCrudToolProvider(object $user): CrudToolProvider
{
    $request = Request::create('/app/ai/assist', 'POST');
    $request->setUserResolver(fn (): object => $user);

    return new CrudToolProvider(
        resolve(CrudService::class),
        resolve(AuthorizationService::class),
        $request,
    );
}

function inAppContext(object $user): AssistantAccessContext
{
    return new AssistantAccessContext(
        AssistantProfile::InAppAssistance,
        (string) $user->getAuthIdentifier(),
        AssistantTenantScope::Global,
        null,
        'en',
        [],
        'conv-test',
    );
}

/**
 * @param  list<ToolDefinition>  $tools
 */
function tool(array $tools, string $name): ?ToolDefinition
{
    foreach ($tools as $definition) {
        if ($definition->name === $name) {
            return $definition;
        }
    }

    return null;
}

beforeEach(function (): void {
    Config::set('ai.features.tools.crud.entities', []);
});

it('offers no tools for a non in-app profile', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['list']]);

    $context = new AssistantAccessContext(
        AssistantProfile::DeveloperHelp,
        null,
        null,
        null,
        'en',
        [],
        null,
    );

    expect(makeCrudToolProvider($user)->tools($context))->toBe([]);
});

it('offers no tools when the request user does not match the context', function (): void {
    $user = user_class()::factory()->create();
    $other = user_class()::factory()->create();
    Auth::login($user);
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['list']]);

    expect(makeCrudToolProvider($user)->tools(inAppContext($other)))->toBe([]);
});

it('offers no tools when the allowlist is empty', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);

    expect(makeCrudToolProvider($user)->tools(inAppContext($user)))->toBe([]);
});

it('builds the allowlisted operations as named tools', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['list', 'create', 'delete', 'bogus']]);

    $tools = makeCrudToolProvider($user)->tools(inAppContext($user));
    $names = array_map(static fn (ToolDefinition $t): string => $t->name, $tools);

    expect($names)->toContain('crud_list_core_setting')
        ->toContain('crud_create_core_setting')
        ->toContain('crud_delete_core_setting')
        // Unknown operations are ignored.
        ->and($names)->not->toContain('crud_bogus_core_setting')
        // Reads are always inline.
        ->and(tool($tools, 'crud_list_core_setting')?->riskLevel)->toBe('low');
});

it('requires approval for a write the user is not permitted to do', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['create']]);

    $create = tool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_create_core_setting');

    expect($create?->riskLevel)->toBe('high');
});

it('runs inline (no approval) for a write the user is already permitted to do', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['create']]);

    $model = DynamicEntity::resolve('setting', module: 'core');
    Permission::factory()->create([
        'name' => PermissionName::forModel($model, 'insert'),
        'guard_name' => 'web',
    ]);
    $user->givePermissionTo(PermissionName::forModel($model, 'insert'));

    $create = tool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_create_core_setting');

    expect($create?->riskLevel)->toBe('low');
});

it('enforces ACL on the read handler for an unpermitted user', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['list']]);

    $list = tool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_list_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($list->handler)();

    expect($result)->toHaveKey('error');
});
