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
 * Grant the acting user a CRUD permission on the core.setting entity.
 */
function grantSettingAbility(object $user, string $ability): void
{
    $model = DynamicEntity::resolve('setting', module: 'core');
    $name = PermissionName::forModel($model, $ability);
    Permission::factory()->create(['name' => $name, 'guard_name' => 'web']);
    $user->givePermissionTo($name);
}

/**
 * @param  list<ToolDefinition>  $tools
 */
function toolNames(array $tools): array
{
    return array_map(static fn (ToolDefinition $t): string => $t->name, $tools);
}

/**
 * @param  list<ToolDefinition>  $tools
 */
function findTool(array $tools, string $name): ?ToolDefinition
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
    grantSettingAbility($user, 'select');
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
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['list']]);

    expect(makeCrudToolProvider($user)->tools(inAppContext($other)))->toBe([]);
});

it('offers no tools when the allowlist is empty', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');

    expect(makeCrudToolProvider($user)->tools(inAppContext($user)))->toBe([]);
});

it('offers no tools for a user without any permission on the entity', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['list', 'create', 'delete']]);

    expect(makeCrudToolProvider($user)->tools(inAppContext($user)))->toBe([]);
});

it('exposes only the operations the user is permitted to perform', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select'); // covers list/detail/search
    grantSettingAbility($user, 'insert'); // covers create
    // No 'forceDelete' → delete must not be exposed.
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['list', 'create', 'delete', 'bogus']]);

    $tools = makeCrudToolProvider($user)->tools(inAppContext($user));
    $names = toolNames($tools);

    expect($names)->toContain('crud_list_core_setting')
        ->toContain('crud_create_core_setting')
        ->and($names)->not->toContain('crud_delete_core_setting')
        ->and($names)->not->toContain('crud_bogus_core_setting')
        // Every exposed tool runs inline; moderation is handled by the model.
        ->and(findTool($tools, 'crud_create_core_setting')?->riskLevel)->toBe('low')
        ->and(findTool($tools, 'crud_list_core_setting')?->riskLevel)->toBe('low');
});

it('runs the read handler and returns data for a permitted user', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['list']]);

    $list = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_list_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($list->handler)();

    expect($result)->toHaveKey('data')
        ->and($result)->not->toHaveKey('error');
});

it('echoes the executed filters and sort in the request block', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['list']]);

    $list = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_list_core_setting');

    $filters = [['property' => 'group_name', 'operator' => '=', 'value' => 'base']];
    $sort = [['property' => 'name', 'direction' => 'asc']];

    /** @var array<string, mixed> $result */
    $result = ($list->handler)(filters: $filters, sort: $sort, limit: 5);

    expect($result)->toHaveKey('request')
        ->and($result)->not->toHaveKey('error')
        ->and($result['request']['verb'])->toBe('list')
        ->and($result['request']['module'])->toBe('core')
        ->and($result['request']['entity'])->toBe('setting')
        ->and($result['request']['filters'])->toBe($filters)
        ->and($result['request']['sort'])->toBe($sort)
        ->and($result['request']['limit'])->toBe(5);
});
