<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Tools\GraphToolProvider;
use Modules\AI\Services\Tools\ContextualToolProviderInterface;
use Modules\AI\Services\Tools\ToolRegistry;
use Modules\Core\Graph\Contracts\GraphToolGatewayInterface;
use Modules\Core\Graph\Data\GraphSearchToolInput;
use Modules\Core\Models\User;

function graphToolAccessContext(AssistantProfile $profile = AssistantProfile::InAppAssistance): AssistantAccessContext
{
    if ($profile === AssistantProfile::DeveloperHelp) {
        return new AssistantAccessContext($profile, null, null, null, 'en', [], null);
    }

    return new AssistantAccessContext(
        $profile,
        '7',
        AssistantTenantScope::Global,
        null,
        'en',
        ['default.users.select'],
        '42',
    );
}

function graphToolRequest(string $userId = '7'): Request
{
    $user = new User;
    $user->setAttribute('id', $userId);
    $request = Request::create('/app/ai', 'POST');
    $request->setUserResolver(static fn (): User => $user);

    return $request;
}

it('binds the contextual provider to the read-only Graph implementation', function (): void {
    expect(app(ContextualToolProviderInterface::class))->toBeInstanceOf(GraphToolProvider::class);
});

it('provides exactly three bounded read-only graph tools for in-app assistance', function (): void {
    $provider = new GraphToolProvider(
        Mockery::mock(GraphToolGatewayInterface::class),
        graphToolRequest(),
    );

    $tools = $provider->tools(graphToolAccessContext());

    expect(array_column($tools, 'name'))->toBe(['graph_search', 'graph_expand', 'graph_stats']);

    foreach ($tools as $tool) {
        $names = array_column($tool->parameters, 'name');

        expect($tool->riskLevel)->toBe('low')
            ->and($names)->not->toContain(
                'user_id',
                'tenant_id',
                'permissions',
                'connection',
                'class',
                'sql',
                'query_json',
                'detail',
            )
            ->and($tool->name)->not->toContain('create')
            ->and($tool->name)->not->toContain('update')
            ->and($tool->name)->not->toContain('delete');

        $depth = collect($tool->parameters)->firstWhere('name', 'depth');
        $relationLimit = collect($tool->parameters)->firstWhere('name', 'relation_limit');

        expect($depth)->toMatchArray(['type' => 'integer', 'minimum' => 1, 'maximum' => 2])
            ->and($relationLimit)->toMatchArray(['type' => 'integer', 'minimum' => 1, 'maximum' => 10]);
    }

    $expandRelations = collect($tools[1]->parameters)->firstWhere('name', 'relations');
    $statsRelations = collect($tools[2]->parameters)->firstWhere('name', 'relations');

    expect($expandRelations)->toMatchArray(['minItems' => 1, 'maxItems' => 10])
        ->and($statsRelations)->toMatchArray(['minItems' => 1, 'maxItems' => 10]);
});

it('does not expose live graph tools to developer help', function (): void {
    $provider = new GraphToolProvider(
        Mockery::mock(GraphToolGatewayInterface::class),
        graphToolRequest(),
    );

    expect($provider->tools(graphToolAccessContext(AssistantProfile::DeveloperHelp)))->toBe([]);
});

it('validates untrusted arguments through Core DTOs before invoking the gateway', function (): void {
    $gateway = Mockery::mock(GraphToolGatewayInterface::class);
    $gateway->shouldReceive('search')
        ->once()
        ->with(Mockery::on(static fn (mixed $input): bool => $input instanceof GraphSearchToolInput
            && $input->module === 'Core'
            && $input->entity === 'users'
            && $input->query === 'Alice'
            && $input->depth === 1
            && $input->limit === 5))
        ->andReturn(['available' => true, 'nodes' => [], 'edges' => [], 'truncated' => false]);

    $provider = new GraphToolProvider($gateway, graphToolRequest());
    $tool = $provider->tools(graphToolAccessContext())[0];
    $handler = $tool->handler;

    expect($handler('Core', 'users', 'Alice', [], 1, 5, 5))->toMatchArray(['available' => true])
        ->and($handler('Core', 'users', 'Alice', [], 3, 100, 100))->toMatchArray(['available' => false])
        ->and($handler('Core', 'users', 'Alice', array_fill(0, 11, 'roles'), 1, 5, 5))->toMatchArray(['available' => false]);
});

it('fails closed if a contextual handler is reused under another authenticated user', function (): void {
    $request = graphToolRequest();
    $gateway = Mockery::mock(GraphToolGatewayInterface::class);
    $gateway->shouldNotReceive('search');
    $provider = new GraphToolProvider($gateway, $request);
    $handler = $provider->tools(graphToolAccessContext())[0]->handler;

    $request->setUserResolver(static fn (): User => tap(new User, static fn (User $user) => $user->setAttribute('id', 99)));

    expect($handler('Core', 'users', 'Alice', [], 1, 5, 5))->toBe([
        'available' => false,
        'nodes' => [],
        'edges' => [],
        'truncated' => false,
    ]);
});

it('executes named NeuronAI inputs without registering contextual tools globally', function (): void {
    $gateway = Mockery::mock(GraphToolGatewayInterface::class);
    $gateway->shouldReceive('search')
        ->once()
        ->with(Mockery::type(GraphSearchToolInput::class))
        ->andReturn(['available' => true, 'nodes' => [], 'edges' => [], 'truncated' => false]);
    $provider = new GraphToolProvider($gateway, graphToolRequest());
    $registry = new ToolRegistry;

    $tools = $registry->getContextualNeuronTools($provider, graphToolAccessContext());
    $tools[0]->setInputs([
        'module' => 'Core',
        'entity' => 'users',
        'query' => 'Alice',
        'relations' => [],
        'depth' => 1,
        'limit' => 5,
        'relation_limit' => 5,
    ]);
    $tools[0]->execute();

    $depthSchema = $tools[0]->getProperties()[4]->getJsonSchema();

    expect($registry->hasTools())->toBeFalse()
        ->and($tools[0]->getResult())->toContain('"available":true')
        ->and($depthSchema)->toMatchArray(['minimum' => 1, 'maximum' => 2]);
});
