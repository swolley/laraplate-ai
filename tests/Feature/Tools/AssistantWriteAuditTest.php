<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\WriteProposal;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Assistance\Writes\AssistantWriteBudget;
use Modules\AI\Services\Assistance\Writes\WriteProposalService;
use Modules\AI\Services\Tools\CrudToolProvider;
use Modules\Core\Models\DynamicEntity;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Export\TabularCsvExporter;
use Modules\Core\Services\Export\TabularPdfExporter;
use Modules\Core\Support\PermissionName;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = user_class()::factory()->create();
    Auth::login($this->user);

    foreach (['select', 'update'] as $ability) {
        $name = PermissionName::forModel(DynamicEntity::resolve('role', module: 'core'), $ability);
        Permission::findOrCreate($name, 'web');
        $this->user->givePermissionTo($name);
    }

    Config::set('ai.features.tools.crud.entities', ['core.role' => ['update']]);
    Config::set('ai.features.tools.crud.unmoderated_writes', ['core.role']);
    $this->role = Role::factory()->create(['name' => 'before']);
    $this->conversation = Conversation::query()->create(['user_id' => $this->user->getKey()]);
    $request = Request::create('/app/ai/assist', 'POST');
    $request->setUserResolver(fn () => $this->user);
    $this->service = resolve(WriteProposalService::class);
    $this->provider = new CrudToolProvider(resolve(CrudService::class), resolve(AuthorizationService::class), $request, resolve(TabularCsvExporter::class), resolve(TabularPdfExporter::class), $this->service);
    $context = new AssistantAccessContext(Modules\AI\Enums\AssistantProfile::InAppAssistance, (string) $this->user->getKey(), Modules\AI\Enums\AssistantTenantScope::Global, null, 'en', [], (string) $this->conversation->getKey());
    $this->tool = collect($this->provider->tools($context))->first();
});

function auditedEvent(string $event): Closure
{
    return static fn (string $message, array $context): bool => $message === 'Assistant write' && ($context['event'] ?? null) === $event;
}

it('logs who proposed what, never the values', function (): void {
    Log::spy();

    ($this->tool->handler)(id: (string) $this->role->getKey(), attributes: ['name' => 'secret-new-name']);

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        return $message === 'Assistant write'
            && $context['event'] === 'proposed'
            && $context['actor_id'] === $this->user->getKey()
            && $context['conversation_id'] === $this->conversation->getKey()
            && $context['tool'] === 'crud_update_core_role'
            && $context['operation'] === 'update'
            && $context['entity'] === 'core.role'
            && mb_strlen($context['payload_hash']) === 64
            && ! str_contains(json_encode($context), 'secret-new-name');
    })->once();
});

it('logs the outcome of a confirmation and of a rejection', function (): void {
    $proposal = WriteProposal::query()->findOrFail(($this->tool->handler)(id: (string) $this->role->getKey(), attributes: ['name' => 'after'])['proposal_id']);
    $other = WriteProposal::query()->findOrFail(($this->tool->handler)(id: (string) $this->role->getKey(), attributes: ['name' => 'later'])['proposal_id']);
    Log::spy();

    $this->service->confirm($proposal, $this->user, $this->provider->applyProposal(...));
    $this->service->reject($other, $this->user);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'Assistant write'
        && $context['event'] === 'confirmed'
        && $context['proposal_id'] === $proposal->getKey()
        && $context['outcome'] === 'applied')->once();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'Assistant write'
        && $context['event'] === 'rejected'
        && $context['proposal_id'] === $other->getKey())->once();
});

it('logs a proposal refused for the turn budget', function (): void {
    for ($i = 0; $i < AssistantWriteBudget::MAX_WRITES_PER_TURN; $i++) {
        ($this->tool->handler)(id: (string) $this->role->getKey(), attributes: ['name' => "n{$i}"]);
    }

    Log::spy();
    ($this->tool->handler)(id: (string) $this->role->getKey(), attributes: ['name' => 'one too many']);

    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'Assistant write'
        && $context['event'] === 'refused'
        && $context['reason'] === 'write_budget_exceeded')->once();
});
