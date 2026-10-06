<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\WriteProposalStatus;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\WriteProposal;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCatalog;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCompiler;
use Modules\AI\Services\Assistance\Writes\WriteProposalService;
use Modules\AI\Services\Tools\CrudToolProvider;
use Modules\AI\Tests\Stubs\Assistance\ScriptedAssistantFixtures;
use Modules\Core\Models\DynamicEntity;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Export\TabularCsvExporter;
use Modules\Core\Services\Export\TabularPdfExporter;
use Modules\Core\Support\PermissionName;
use NeuronAI\Tools\Tool;

uses(RefreshDatabase::class);

/**
 * What the model does when it calls a tool of the turn.
 *
 * @param  list<Tool>  $tools
 * @param  array<string, mixed>  $inputs
 */
function governedToolCall(array $tools, string $name, array $inputs): string
{
    foreach ($tools as $tool) {
        if ($name === $tool->getName()) {
            $tool->setInputs($inputs)->execute();

            return $tool->getResult();
        }
    }

    throw new RuntimeException("The model was not offered the tool {$name}.");
}

beforeEach(function (): void {
    $this->user = user_class()::factory()->create();
    Auth::login($this->user);

    foreach (['select', 'update'] as $ability) {
        $name = PermissionName::forModel(DynamicEntity::resolve('role', module: 'core'), $ability);
        Permission::findOrCreate($name, 'web');
        $this->user->givePermissionTo($name);
    }

    Config::set('ai.features.tools.crud.entities', ['core.role' => ['list', 'update']]);
    Config::set('ai.features.tools.crud.unmoderated_writes', ['core.role']);
    $this->role = Role::factory()->create(['name' => 'before']);
    $this->conversation = Conversation::query()->create(['user_id' => $this->user->getKey()]);

    $this->request = Request::create('/app/ai/assistance', 'POST', ['message' => 'hello']);
    $this->request->setUserResolver(fn () => $this->user);
    $this->provider = new CrudToolProvider(
        resolve(CrudService::class),
        resolve(AuthorizationService::class),
        $this->request,
        resolve(TabularCsvExporter::class),
        resolve(TabularPdfExporter::class),
        resolve(WriteProposalService::class),
    );
});

it('turns a write the model proposes into a stored proposal, changes nothing and says so in the message', function (): void {
    $service = ScriptedAssistantFixtures::inAppService(
        $this->request,
        function (string $input, string $system, mixed $context, array $tools): string {
            governedToolCall($tools, 'crud_update_core_role', ['id' => (string) $this->role->getKey(), 'attributes' => ['name' => 'after']]);

            return 'I have updated the role name.';
        },
        tools: $this->provider,
    );

    $reply = $service->respond($this->conversation, $this->user, 'Rename the role.');
    $writes = $reply->metadata['writes'];

    expect($this->role->fresh()->name)->toBe('before')
        ->and($writes)->toHaveCount(1)
        ->and($writes[0]['status'])->toBe('proposed')
        ->and($writes[0]['tool'])->toBe('crud_update_core_role')
        ->and($writes[0]['operation'])->toBe('update')
        ->and($writes[0]['acting_user_id'])->toBe($this->user->getKey())
        ->and($writes[0]['acting_user_name'])->toBeString()
        ->and($writes[0]['summary']['changes']['name'])->toBe(['from' => 'before', 'to' => 'after'])
        ->and($reply->content)->not->toContain('I have updated')
        ->and($reply->content)->toContain('Nothing changes until you confirm it');

    $proposal = WriteProposal::query()->findOrFail($writes[0]['id']);

    expect($proposal->status)->toBe(WriteProposalStatus::Proposed);
});

it('keeps the model\'s own words when they do not claim a change, and adds the notice', function (): void {
    $service = ScriptedAssistantFixtures::inAppService(
        $this->request,
        function (string $input, string $system, mixed $context, array $tools): string {
            governedToolCall($tools, 'crud_update_core_role', ['id' => (string) $this->role->getKey(), 'attributes' => ['name' => 'after']]);

            return 'I propose renaming the role from "before" to "after".';
        },
        tools: $this->provider,
    );

    $reply = $service->respond($this->conversation, $this->user, 'Rename the role.');

    expect($reply->content)->toStartWith('I propose renaming the role')
        ->and($reply->content)->toContain('Nothing changes until you confirm it');
});

it('says it in Italian for an Italian user', function (): void {
    $this->user->forceFill(['lang' => 'it'])->saveQuietly();
    $service = ScriptedAssistantFixtures::inAppService(
        $this->request,
        function (string $input, string $system, mixed $context, array $tools): string {
            governedToolCall($tools, 'crud_update_core_role', ['id' => (string) $this->role->getKey(), 'attributes' => ['name' => 'after']]);

            return 'Ho modificato il ruolo.';
        },
        tools: $this->provider,
    );

    $reply = $service->respond($this->conversation, $this->user->fresh(), 'Rinomina il ruolo.');

    expect($reply->content)->toBe('Ho preparato una modifica da confermare. Non cambia nulla finché non la confermi.');
});

it('carries no writes when the turn proposed none', function (): void {
    $service = ScriptedAssistantFixtures::inAppService($this->request, fn (): string => 'Here is how the roles page works.', tools: $this->provider);

    $reply = $service->respond($this->conversation, $this->user, 'What is the roles page?');

    expect($reply->metadata)->not->toHaveKey('writes')
        ->and($reply->content)->not->toContain('Nothing changes');
});

it('offers no write tool when the policy does not grant the capability', function (): void {
    $catalog = AssistantPolicyCatalog::defaults();
    $without = new AssistantPolicyCatalog(
        $catalog->version,
        $catalog->globalPolicy,
        $catalog->profiles,
        array_diff_key($catalog->capabilities, ['governed_writes' => true]),
        $catalog->modules,
    );
    $offered = [];
    $service = ScriptedAssistantFixtures::inAppService(
        $this->request,
        function (string $input, string $system, mixed $context, array $tools) use (&$offered): string {
            $offered = array_map(static fn (Tool $tool): string => $tool->getName(), $tools);

            return 'Understood.';
        },
        compiler: new AssistantPolicyCompiler($without),
        tools: $this->provider,
    );

    $reply = $service->respond($this->conversation, $this->user, 'Rename the role.');

    // The compiler refuses a capability its catalog does not have: the turn is refused, nothing is offered or stored.
    expect($offered)->toBe([])
        ->and($reply->metadata)->toHaveKey('refused')
        ->and(WriteProposal::query()->count())->toBe(0);
});

it('starts every turn from an empty budget and an empty list of proposals', function (): void {
    $answers = ['first', 'second'];
    $service = ScriptedAssistantFixtures::inAppService(
        $this->request,
        function (string $input, string $system, mixed $context, array $tools) use (&$answers): string {
            if (array_shift($answers) === 'first') {
                governedToolCall($tools, 'crud_update_core_role', ['id' => (string) $this->role->getKey(), 'attributes' => ['name' => 'after']]);
            }

            return 'Nothing to propose here.';
        },
        tools: $this->provider,
    );

    $first = $service->respond($this->conversation, $this->user, 'Rename the role.');
    $second = $service->respond($this->conversation, $this->user, 'And now?');

    expect($first->metadata)->toHaveKey('writes')
        ->and($second->metadata)->not->toHaveKey('writes');
});

it('does not let the developer-help profile receive the write capability', function (): void {
    expect(fn () => (new AssistantPolicyCompiler(AssistantPolicyCatalog::defaults()))->compile(AssistantProfile::DeveloperHelp, ['governed_writes']))
        ->toThrow(InvalidArgumentException::class);
});
