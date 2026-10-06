<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Enums\WriteProposalStatus;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\WriteProposal;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Assistance\Writes\AssistantWriteBudget;
use Modules\AI\Services\Assistance\Writes\WriteProposalService;
use Modules\AI\Services\Tools\CrudToolProvider;
use Modules\AI\Services\Tools\ToolDefinition;
use Modules\Core\Models\DynamicEntity;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Export\TabularCsvExporter;
use Modules\Core\Services\Export\TabularPdfExporter;
use Modules\Core\Support\PermissionName;

uses(RefreshDatabase::class);

function proposalProvider(object $user): CrudToolProvider
{
    $request = Request::create('/app/ai/assist', 'POST');
    $request->setUserResolver(fn (): object => $user);

    return new CrudToolProvider(
        resolve(CrudService::class),
        resolve(AuthorizationService::class),
        $request,
        resolve(TabularCsvExporter::class),
        resolve(TabularPdfExporter::class),
        resolve(WriteProposalService::class),
    );
}

function proposalContext(object $user, Conversation $conversation): AssistantAccessContext
{
    return new AssistantAccessContext(AssistantProfile::InAppAssistance, (string) $user->getAuthIdentifier(), AssistantTenantScope::Global, null, 'en', [], (string) $conversation->getKey());
}

function grantOn(object $user, string $entity, array $abilities): void
{
    foreach ($abilities as $ability) {
        $name = PermissionName::forModel(DynamicEntity::resolve($entity, module: 'core'), $ability);
        Permission::findOrCreate($name, 'web');
        $user->givePermissionTo($name);
    }
}

function writeTool(CrudToolProvider $provider, AssistantAccessContext $context, string $name): ?ToolDefinition
{
    return collect($provider->tools($context))->first(static fn (ToolDefinition $tool): bool => $tool->name === $name);
}

beforeEach(function (): void {
    $this->user = user_class()::factory()->create();
    Auth::login($this->user);
    $this->conversation = Conversation::query()->create(['user_id' => $this->user->getKey()]);
    $this->context = proposalContext($this->user, $this->conversation);
    $this->service = resolve(WriteProposalService::class);

    grantOn($this->user, 'role', ['select', 'insert', 'update', 'forceDelete']);
    Config::set('ai.features.tools.crud.entities', ['core.role' => ['create', 'update', 'delete', 'bulk_update', 'bulk_delete']]);
    Config::set('ai.features.tools.crud.unmoderated_writes', ['core.role']);

    $this->provider = proposalProvider($this->user);
    $this->template = Role::factory()->create(['name' => 'before']);
});

it('stores a proposal and changes nothing when the model calls a write tool', function (): void {
    $crud = Mockery::spy(CrudService::class);
    $request = Request::create('/app/ai/assist', 'POST');
    $request->setUserResolver(fn (): object => $this->user);
    $provider = new CrudToolProvider(
        $crud,
        resolve(AuthorizationService::class),
        $request,
        resolve(TabularCsvExporter::class),
        resolve(TabularPdfExporter::class),
        $this->service,
    );
    $tool = writeTool($provider, $this->context, 'crud_create_core_role');

    $result = ($tool->handler)(attributes: ['name' => 'new', 'guard_name' => 'web']);

    expect($result['status'])->toBe('awaiting_confirmation')
        ->and($result['acting_user'])->toBeString()
        ->and($result['message'])->toContain('Nothing has been changed');

    $crud->shouldNotHaveReceived('insert');
    $crud->shouldNotHaveReceived('update');
    $crud->shouldNotHaveReceived('delete');

    $stored = WriteProposal::query()->findOrFail($result['proposal_id']);

    expect($stored->status)->toBe(WriteProposalStatus::Proposed)
        ->and($stored->user_id)->toBe($this->user->getKey())
        ->and($stored->conversation_id)->toBe($this->conversation->getKey())
        ->and($stored->operation)->toBe('create')
        ->and($stored->payload)->toBe(['attributes' => ['name' => 'new', 'guard_name' => 'web']])
        ->and($this->service->proposedInTurn())->toHaveCount(1);
});

it('shows what an update would change against what the record is now', function (): void {
    $tool = writeTool($this->provider, $this->context, 'crud_update_core_role');

    $result = ($tool->handler)(id: (string) $this->template->getKey(), attributes: ['name' => 'after']);

    expect($result['summary']['changes']['name'])->toBe(['from' => 'before', 'to' => 'after'])
        ->and($this->template->fresh()->name)->toBe('before');
});

it('does not propose a change to a record the person cannot read, or one that does not exist', function (): void {
    $tool = writeTool($this->provider, $this->context, 'crud_update_core_role');

    $result = ($tool->handler)(id: '999999', attributes: ['name' => 'x']);

    expect($result)->toHaveKey('error')
        ->and(WriteProposal::query()->count())->toBe(0);
});

it('applies the stored payload when the person confirms, once', function (): void {
    $tool = writeTool($this->provider, $this->context, 'crud_update_core_role');
    $proposal = WriteProposal::query()->findOrFail(($tool->handler)(id: (string) $this->template->getKey(), attributes: ['name' => 'after'])['proposal_id']);

    $first = $this->service->confirm($proposal, $this->user, $this->provider->applyProposal(...));
    $applications = 0;
    $second = $this->service->confirm($proposal, $this->user, function () use (&$applications): never {
        $applications++;

        throw new LogicException('applied twice');
    });

    expect($first->status)->toBe(WriteProposalStatus::Applied)
        ->and($this->template->fresh()->name)->toBe('after')
        ->and($second->status)->toBe(WriteProposalStatus::Applied)
        ->and($applications)->toBe(0)
        ->and($first->resolved_at)->not->toBeNull();
});

it('applies a bulk change only to the ids the person was shown', function (): void {
    Role::factory()->create(['name' => 'bulk-one', 'description' => 'orig']);
    Role::factory()->create(['name' => 'bulk-two', 'description' => 'orig']);
    $tool = writeTool($this->provider, $this->context, 'crud_bulk_update_core_role');

    $result = ($tool->handler)(filters: [['property' => 'description', 'operator' => '=', 'value' => 'orig']], attributes: ['description' => 'changed']);
    $late = Role::factory()->create(['name' => 'bulk-late', 'description' => 'orig']);

    $resolved = $this->service->confirm(WriteProposal::query()->findOrFail($result['proposal_id']), $this->user, $this->provider->applyProposal(...));

    expect($result['summary']['matched_records'])->toBe(2)
        ->and($resolved->status)->toBe(WriteProposalStatus::Applied)
        ->and($resolved->outcome['applied'])->toBe(2)
        ->and($resolved->outcome['failed'])->toBe(0)
        ->and(Role::query()->where('description', 'changed')->count())->toBe(2)
        ->and($late->fresh()->description)->toBe('orig');
});

it('refuses to confirm for another user, after expiry, after a rejection, and after the permission is lost', function (): void {
    $tool = writeTool($this->provider, $this->context, 'crud_update_core_role');
    $make = fn (): WriteProposal => WriteProposal::query()->findOrFail(($tool->handler)(id: (string) $this->template->getKey(), attributes: ['name' => 'after'])['proposal_id']);
    $apply = $this->provider->applyProposal(...);
    $other = user_class()::factory()->create();

    $someones = $this->service->confirm($make(), $other, $apply);
    $expired = $make();
    $expired->update(['expires_at' => now()->subMinute()]);
    $afterExpiry = $this->service->confirm($expired, $this->user, $apply);
    $rejected = $this->service->reject($make(), $this->user);
    $afterReject = $this->service->confirm($rejected, $this->user, $apply);

    $this->user->revokePermissionTo(PermissionName::forModel(DynamicEntity::resolve('role', module: 'core'), 'update'));
    $lost = $this->service->confirm($make(), $this->user, $apply);

    expect($someones->status)->toBe(WriteProposalStatus::Proposed)
        ->and($afterExpiry->status)->toBe(WriteProposalStatus::Expired)
        ->and($afterReject->status)->toBe(WriteProposalStatus::Rejected)
        ->and($lost->status)->toBe(WriteProposalStatus::Failed)
        ->and($this->template->fresh()->name)->toBe('before');
});

it('refuses a stored proposal once the operator no longer offers the operation', function (): void {
    $tool = writeTool($this->provider, $this->context, 'crud_update_core_role');
    $proposal = WriteProposal::query()->findOrFail(($tool->handler)(id: (string) $this->template->getKey(), attributes: ['name' => 'after'])['proposal_id']);

    Config::set('ai.features.tools.crud.unmoderated_writes', []);
    $resolved = $this->service->confirm($proposal, $this->user, $this->provider->applyProposal(...));

    expect($resolved->status)->toBe(WriteProposalStatus::Failed)
        ->and($this->template->fresh()->name)->toBe('before');
});

it('limits how many proposals a turn can create, and starts the next turn from zero', function (): void {
    $tool = writeTool($this->provider, $this->context, 'crud_create_core_role');

    for ($i = 0; $i < AssistantWriteBudget::MAX_WRITES_PER_TURN; $i++) {
        expect(($tool->handler)(attributes: ['name' => "n{$i}", 'guard_name' => 'web'])['status'])->toBe('awaiting_confirmation');
    }

    $refused = ($tool->handler)(attributes: ['name' => 'one too many']);
    $this->service->startTurn();
    $next = ($tool->handler)(attributes: ['name' => 'next turn']);

    expect($refused['refused'])->toBeTrue()
        ->and($next['status'])->toBe('awaiting_confirmation')
        ->and(WriteProposal::query()->count())->toBe(AssistantWriteBudget::MAX_WRITES_PER_TURN + 1);
});

it('never consumes the write budget on a read', function (): void {
    Config::set('ai.features.tools.crud.entities', ['core.role' => ['list', 'create']]);
    $list = writeTool($this->provider, $this->context, 'crud_list_core_role');

    for ($i = 0; $i < AssistantWriteBudget::MAX_WRITES_PER_TURN + 2; $i++) {
        ($list->handler)();
    }

    expect(resolve(AssistantWriteBudget::class)->used())->toBe(0);
});

it('has no parameter with which the model could confirm a write itself', function (): void {
    foreach (['crud_create_core_role', 'crud_update_core_role', 'crud_delete_core_role', 'crud_bulk_update_core_role', 'crud_bulk_delete_core_role'] as $name) {
        $names = array_column(writeTool($this->provider, $this->context, $name)->parameters, 'name');

        expect($names)->not->toContain('confirm')->not->toContain('confirmation_token');
    }
});

it('asks a privileged user too: a proposal is stored even when the person could write directly', function (): void {
    $this->user->assignRole(Role::findOrCreate((string) config('permission.roles.superadmin'), 'web'));
    $tool = writeTool(proposalProvider($this->user), $this->context, 'crud_create_core_role');

    $result = ($tool->handler)(attributes: ['name' => 'by an admin']);

    expect($result['status'])->toBe('awaiting_confirmation')
        ->and(Role::query()->where('name', 'by an admin')->exists())->toBeFalse();
});
