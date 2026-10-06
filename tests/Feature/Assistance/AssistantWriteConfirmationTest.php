<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Modules\AI\Enums\WriteProposalStatus;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\WriteProposal;
use Modules\Core\Models\DynamicEntity;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Support\PermissionName;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = user_class()::factory()->create();

    foreach (['select', 'update'] as $ability) {
        $name = PermissionName::forModel(DynamicEntity::resolve('role', module: 'core'), $ability);
        Permission::findOrCreate($name, 'web');
        $this->user->givePermissionTo($name);
    }

    Config::set('ai.features.tools.crud.entities', ['core.role' => ['update']]);
    Config::set('ai.features.tools.crud.unmoderated_writes', ['core.role']);

    $this->role = Role::factory()->create(['name' => 'before']);
    $this->conversation = Conversation::query()->create(['user_id' => $this->user->getKey()]);
    $this->proposal = WriteProposal::factory()->create([
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->conversation->getKey(),
        'tool' => 'crud_update_core_role',
        'module' => 'core',
        'entity' => 'role',
        'operation' => 'update',
        'payload' => ['id' => (string) $this->role->getKey(), 'attributes' => ['name' => 'after']],
        'summary' => ['record_id' => (string) $this->role->getKey(), 'changes' => ['name' => ['from' => 'before', 'to' => 'after']]],
    ]);
});

it('applies the proposal as the signed-in person when they confirm it', function (): void {
    $this->actingAs($this->user)
        ->postJson(route('ai.crud.assistant-writes.confirm', $this->proposal))
        ->assertOk()
        ->assertJsonPath('data.status', 'applied')
        ->assertJsonPath('data.acting_user_id', $this->user->getKey());

    expect($this->role->fresh()->name)->toBe('after');
});

it('confirms once: the second confirmation answers the same outcome and writes nothing more', function (): void {
    $this->actingAs($this->user)->postJson(route('ai.crud.assistant-writes.confirm', $this->proposal))->assertOk();
    $this->role->forceFill(['name' => 'changed elsewhere'])->saveQuietly();

    $this->actingAs($this->user)
        ->postJson(route('ai.crud.assistant-writes.confirm', $this->proposal))
        ->assertOk()
        ->assertJsonPath('data.status', 'applied');

    expect($this->role->fresh()->name)->toBe('changed elsewhere');
});

it('refuses another user without writing', function (): void {
    $this->actingAs(user_class()::factory()->create())->postJson(route('ai.crud.assistant-writes.confirm', $this->proposal))->assertForbidden();

    expect($this->role->fresh()->name)->toBe('before')
        ->and($this->proposal->fresh()->status)->toBe(WriteProposalStatus::Proposed);
});

it('answers a conflict for a proposal that has expired or was rejected', function (): void {
    $expired = WriteProposal::factory()->expired()->create([
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->conversation->getKey(),
        'payload' => $this->proposal->payload,
    ]);

    $this->actingAs($this->user)->postJson(route('ai.crud.assistant-writes.confirm', $expired))->assertConflict()->assertJsonPath('data.status', 'expired');
    $this->actingAs($this->user)->postJson(route('ai.crud.assistant-writes.reject', $this->proposal))->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->actingAs($this->user)->postJson(route('ai.crud.assistant-writes.confirm', $this->proposal))->assertConflict();

    expect($this->role->fresh()->name)->toBe('before');
});

it('refuses a request nobody signed in for', function (): void {
    $this->postJson(route('ai.crud.assistant-writes.confirm', $this->proposal))->assertUnauthorized();

    expect($this->role->fresh()->name)->toBe('before');
});

it('shows a proposal to its owner', function (): void {
    $this->actingAs($this->user)
        ->getJson(route('ai.crud.assistant-writes.show', $this->proposal))
        ->assertOk()
        ->assertJsonPath('data.summary.changes.name.to', 'after');
});

it('does not show a proposal to anyone else', function (): void {
    $this->actingAs(user_class()::factory()->create())
        ->getJson(route('ai.crud.assistant-writes.show', $this->proposal))
        ->assertForbidden();
});

it('records a failure instead of applying when the person no longer has the permission', function (): void {
    $this->user->revokePermissionTo(PermissionName::forModel(DynamicEntity::resolve('role', module: 'core'), 'update'));

    $this->actingAs($this->user)
        ->postJson(route('ai.crud.assistant-writes.confirm', $this->proposal))
        ->assertOk()
        ->assertJsonPath('data.status', 'failed');

    expect($this->role->fresh()->name)->toBe('before');
});
