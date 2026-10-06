<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Middleware\ResolveAssistantApplicationContext;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;
use Modules\Core\Support\PermissionName;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * What the middleware leaves in the attribute that InAppAssistanceService reads, for a message
 * carrying the given `context.page`, sent by the given user.
 *
 * @param  array<string, mixed>  $context
 * @return array<string, mixed>|null
 */
function resolvedAssistantContext(User $user, array $context): ?array
{
    $request = Request::create('/probe', 'POST', ['message' => 'hello', 'context' => $context]);
    $request->setUserResolver(fn (): User => $user);

    $seen = 'next was not called';

    app(ResolveAssistantApplicationContext::class)->handle($request, function (Request $request) use (&$seen) {
        $seen = $request->attributes->get('assistant_application_context');

        return response('ok');
    });

    return $seen;
}

function assistantReader(string $table_model_class = Setting::class): User
{
    $user = User::factory()->create();
    $permission = PermissionName::forClass($table_model_class, 'select');
    Permission::findOrCreate($permission, 'web');
    $user->givePermissionTo($permission);

    return $user;
}

it('resolves a known resource the user may read into the server-owned context', function (): void {
    $user = assistantReader();

    expect(resolvedAssistantContext($user, ['page' => ['resource' => 'core/settings', 'recordKey' => 42]]))
        ->toBe(['module' => 'core', 'entity' => 'settings', 'record_key' => 42]);
});

it('keeps a string record key and drops one that is not a key', function (mixed $record_key, int|string|null $expected): void {
    $user = assistantReader();

    expect(resolvedAssistantContext($user, ['page' => ['resource' => 'core/settings', 'recordKey' => $record_key]]))
        ->toBe(['module' => 'core', 'entity' => 'settings', 'record_key' => $expected]);
})->with([
    'string' => ['abc-123', 'abc-123'],
    'array' => [[1, 2], null],
    'empty string' => ['', null],
    'too long' => [str_repeat('k', 256), null],
    'bool' => [true, null],
    'absent' => [null, null],
]);

it('yields no context for a resource that cannot be resolved', function (mixed $resource): void {
    $user = assistantReader();

    expect(resolvedAssistantContext($user, ['page' => ['resource' => $resource, 'recordKey' => 1]]))->toBeNull();
})->with([
    'unknown entity' => ['core/no_such_entity'],
    'unknown module' => ['no_such_module/settings'],
    'entity of another module' => ['erp/settings'],
    'no module' => ['settings'],
    'upper case' => ['Core/Settings'],
    'too many segments' => ['core/settings/extra'],
    'path traversal' => ['../core/settings'],
    'empty' => [''],
    'integer' => [42],
    'list' => [['core', 'settings']],
]);

it('yields no context for a resource the user may not read', function (): void {
    $user = User::factory()->create();

    expect(resolvedAssistantContext($user, ['page' => ['resource' => 'core/settings']]))->toBeNull();
});

it('yields no context when the message carries no page', function (array $context): void {
    $user = assistantReader();

    expect(resolvedAssistantContext($user, $context))->toBeNull();
})->with([
    'no context' => [[]],
    'locale only' => [['locale' => 'it']],
    'page is not an object' => [['page' => 'core/settings']],
]);

it('never lets a client set the attribute through the context', function (): void {
    $user = assistantReader();

    expect(resolvedAssistantContext($user, [
        'assistant_application_context' => ['module' => 'erp', 'entity' => 'orders', 'record_key' => 1],
        'page' => ['assistant_application_context' => ['module' => 'erp'], 'resource' => 'core/settings'],
    ]))->toBe(['module' => 'core', 'entity' => 'settings', 'record_key' => null]);

    expect(resolvedAssistantContext($user, [
        'assistant_application_context' => ['module' => 'erp', 'entity' => 'orders', 'record_key' => 1],
    ]))->toBeNull();
});

it('overwrites an attribute that was already on the request', function (): void {
    $user = User::factory()->create();
    $request = Request::create('/probe', 'POST', ['message' => 'hello']);
    $request->setUserResolver(fn (): User => $user);
    $request->attributes->set('assistant_application_context', ['module' => 'erp']);

    app(ResolveAssistantApplicationContext::class)->handle($request, fn (Request $request) => response('ok'));

    expect($request->attributes->has('assistant_application_context'))->toBeFalse();
});

it('is the only writer on the routes that send a message to the assistant', function (string $route): void {
    $middleware = Route::getRoutes()->getByName($route)?->gatherMiddleware() ?? [];

    expect($middleware)->toContain(ResolveAssistantApplicationContext::class);
})->with([
    'ai.crud.messages.insert',
    'ai.crud.messages.stream',
]);
