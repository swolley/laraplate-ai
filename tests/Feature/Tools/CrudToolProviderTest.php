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
        resolve(Modules\Core\Services\Export\TabularCsvExporter::class),
        resolve(Modules\Core\Services\Export\TabularPdfExporter::class),
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

it('proposes a table view without fetching data (configure mode)', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['view']]);

    $view = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_view_core_setting');

    expect($view)->not->toBeNull()
        ->and($view->riskLevel)->toBe('low');

    $filters = [['property' => 'group_name', 'operator' => '=', 'value' => 'base']];

    /** @var array<string, mixed> $result */
    $result = ($view->handler)(filters: $filters, sort: [], limit: 10, page: 1);

    expect($result['apply'])->toBeTrue()
        ->and($result)->not->toHaveKey('data') // configure mode does not fetch
        ->and($result['request']['verb'])->toBe('view')
        ->and($result['request']['filters'])->toBe($filters)
        ->and($result['request']['limit'])->toBe(10)
        ->and($result['request']['page'])->toBe(1);
});

it('exposes approval tools only with the approve permission', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['pending_approvals', 'approve', 'disapprove']]);

    // Without the approve permission → no approval tools.
    expect(makeCrudToolProvider($user)->tools(inAppContext($user)))->toBe([]);

    grantSettingAbility($user, 'approve');
    $names = toolNames(makeCrudToolProvider($user)->tools(inAppContext($user)));

    expect($names)->toContain('crud_pending_approvals_core_setting')
        ->toContain('crud_approve_core_setting')
        ->toContain('crud_disapprove_core_setting');
});

it('summarizes records with group-by count and sum metrics', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['summarize']]);

    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['id' => 10, 'name' => 's1', 'group_name' => 'alpha']);
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['id' => 20, 'name' => 's2', 'group_name' => 'alpha']);
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['id' => 30, 'name' => 's3', 'group_name' => 'beta']);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_summarize_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(
        group_by: ['group_name'],
        metrics: [['property' => 'id', 'function' => 'sum']],
    );

    expect($result)->not->toHaveKey('error')
        ->and($result['request']['verb'])->toBe('summarize')
        ->and($result['request']['group_by'])->toBe(['group_name'])
        ->and($result['meta']['total_records'])->toBe(3)
        ->and($result['meta']['truncated'])->toBeFalse();

    $byGroup = collect($result['data'])->keyBy(fn (array $b): string => $b['group']['group_name']);

    expect($byGroup['alpha']['count'])->toBe(2)
        ->and($byGroup['alpha']['metrics']['sum(id)'])->toBe(30.0)
        ->and($byGroup['beta']['count'])->toBe(1)
        ->and($byGroup['beta']['metrics']['sum(id)'])->toBe(30.0);
});

it('exports records to a csv file with the selected columns', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['export']]);

    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'alpha', 'group_name' => 'g1']);
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'beta', 'group_name' => 'g2']);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_export_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(format: 'csv', columns: ['name', 'group_name']);

    expect($result)->not->toHaveKey('error')
        ->and($result['request']['verb'])->toBe('export')
        ->and($result['request']['format'])->toBe('csv')
        ->and($result['request']['columns'])->toBe(['name', 'group_name'])
        ->and($result['file']['mime'])->toBe('text/csv')
        ->and($result['file']['encoding'])->toBe('base64')
        ->and($result['file']['filename'])->toBe('core_setting_export.csv')
        ->and($result['meta']['exported_rows'])->toBe(2)
        ->and($result['meta']['truncated'])->toBeFalse();

    $csv = base64_decode($result['file']['contents'], true);

    expect($csv)->toContain('name,group_name')
        ->and($csv)->toContain('alpha,g1')
        ->and($csv)->toContain('beta,g2');
});

it('exports records to a pdf file', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['export']]);

    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'alpha', 'group_name' => 'g1']);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_export_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(format: 'pdf', columns: ['name']);

    expect($result)->not->toHaveKey('error')
        ->and($result['file']['mime'])->toBe('application/pdf')
        ->and($result['file']['filename'])->toBe('core_setting_export.pdf');

    $pdf = base64_decode($result['file']['contents'], true);

    expect($pdf)->toStartWith('%PDF-1.4');
});

it('exposes bulk tools only with select plus the matching write permission', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'update'); // write ability, but no select yet
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['bulk_update', 'bulk_delete']]);

    // Without select, bulk cannot resolve the affected set → not offered.
    expect(toolNames(makeCrudToolProvider($user)->tools(inAppContext($user))))->toBe([]);

    grantSettingAbility($user, 'select'); // now bulk_update is fully permitted
    $names = toolNames(makeCrudToolProvider($user)->tools(inAppContext($user)));

    expect($names)->toContain('crud_bulk_update_core_setting')
        ->and($names)->not->toContain('crud_bulk_delete_core_setting'); // no forceDelete
});

it('previews a bulk update without changing anything', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    grantSettingAbility($user, 'update');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['bulk_update']]);

    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'b1', 'group_name' => 'bulk', 'description' => 'orig']);
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'b2', 'group_name' => 'bulk', 'description' => 'orig']);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_bulk_update_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(
        filters: [['property' => 'group_name', 'operator' => '=', 'value' => 'bulk']],
        attributes: ['description' => 'changed'],
    );

    expect($result['preview'])->toBeTrue()
        ->and($result['meta']['matched_records'])->toBe(2)
        ->and($result['meta']['exceeds_cap'])->toBeFalse()
        ->and($result['request']['confirm'])->toBeFalse();

    expect(Modules\Core\Models\Setting::where('group_name', 'bulk')->where('description', 'orig')->count())->toBe(2);
});

it('applies a bulk update when confirmed', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    grantSettingAbility($user, 'update');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['bulk_update']]);

    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'b1', 'group_name' => 'bulk', 'description' => 'orig']);
    Modules\Core\Models\Setting::factory()->persistedWithoutApprovalCapture()->create(['name' => 'b2', 'group_name' => 'bulk', 'description' => 'orig']);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_bulk_update_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(
        filters: [['property' => 'group_name', 'operator' => '=', 'value' => 'bulk']],
        attributes: ['description' => 'changed'],
        confirm: true,
    );

    expect($result)->not->toHaveKey('error')
        ->and($result['meta']['applied'])->toBe(2)
        ->and($result['meta']['failed'])->toBe(0);

    expect(Modules\Core\Models\Setting::where('group_name', 'bulk')->where('description', 'changed')->count())->toBe(2);
});

it('refuses a bulk operation without filters', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'select');
    grantSettingAbility($user, 'forceDelete');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['bulk_delete']]);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_bulk_delete_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(confirm: true);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('at least one filter');
});

it('lists pending approvals and echoes the author filter', function (): void {
    $user = user_class()::factory()->create();
    Auth::login($user);
    grantSettingAbility($user, 'approve');
    Config::set('ai.features.tools.crud.entities', ['core.setting' => ['pending_approvals']]);

    $tool = findTool(makeCrudToolProvider($user)->tools(inAppContext($user)), 'crud_pending_approvals_core_setting');

    /** @var array<string, mixed> $result */
    $result = ($tool->handler)(author: 'Marco');

    expect($result)->not->toHaveKey('error')
        ->and($result['request']['verb'])->toBe('pending_approvals')
        ->and($result['request']['author'])->toBe('Marco')
        ->and($result['data'])->toBeArray();
});
