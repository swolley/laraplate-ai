<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\ActionRequestService;
use Modules\AI\Services\Tools\RiskClassifier;
use Modules\AI\Services\Tools\ToolDefinition;
use Modules\AI\Services\Tools\ToolRegistry;
use Modules\Core\Models\User;
use NeuronAI\Tools\Tool;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->registry = new ToolRegistry;
});

it('registers a tool and retrieves it by name', function (): void {
    $this->registry->register(
        'get_weather',
        fn (string $city): string => "Sunny in {$city}",
        'Get current weather',
        [['name' => 'city', 'type' => 'string', 'description' => 'City name']],
    );

    $tool = $this->registry->getTool('get_weather');

    expect($tool)
        ->toBeInstanceOf(ToolDefinition::class)
        ->and($tool->name)->toBe('get_weather')
        ->and($tool->description)->toBe('Get current weather')
        ->and($tool->riskLevel)->toBe('low');
});

it('returns null for non-registered tool', function (): void {
    expect($this->registry->getTool('unknown'))->toBeNull();
});

it('reports hasTools correctly', function (): void {
    expect($this->registry->hasTools())->toBeFalse();

    $this->registry->register('test', static fn (): string => 'ok', 'Test', []);

    expect($this->registry->hasTools())->toBeTrue();
});

it('returns all tools as definitions', function (): void {
    $this->registry->register('tool_a', static fn (): string => 'a', 'Tool A', []);
    $this->registry->register('tool_b', static fn (): string => 'b', 'Tool B', []);

    $all = $this->registry->getAllTools();

    expect($all)->toHaveCount(2)
        ->and($all[0]->name)->toBe('tool_a')
        ->and($all[1]->name)->toBe('tool_b');
});

it('builds NeuronAI Tool instances via getAllNeuronTools', function (): void {
    $handler = static fn (string $q): string => "Result: {$q}";

    $this->registry->register(
        'search',
        $handler,
        'Search something',
        [['name' => 'query', 'type' => 'string', 'description' => 'Search query']],
    );

    $tools = $this->registry->getAllNeuronTools();

    expect($tools)->toHaveCount(1)
        ->and($tools[0])->toBeInstanceOf(Tool::class)
        ->and($tools[0]->getName())->toBe('search')
        ->and($tools[0]->getDescription())->toBe('Search something');
});

it('builds tools with correct parameter properties', function (): void {
    $this->registry->register(
        'calculate',
        fn (int $a, int $b): int => $a + $b,
        'Add two numbers',
        [
            ['name' => 'a', 'type' => 'integer', 'description' => 'First number'],
            ['name' => 'b', 'type' => 'integer', 'description' => 'Second number'],
        ],
    );

    $tools = $this->registry->getAllNeuronTools();
    $properties = $tools[0]->getProperties();

    expect($properties)->toHaveCount(2)
        ->and($properties[0]->getName())->toBe('a')
        ->and($properties[1]->getName())->toBe('b');
});

it('executes callable correctly on built neuron tools', function (): void {
    $this->registry->register(
        'greet',
        fn (string $name): string => "Hello, {$name}!",
        'Greet someone',
        [['name' => 'name', 'type' => 'string', 'description' => 'Name to greet']],
    );

    $tools = $this->registry->getAllNeuronTools();
    $tools[0]->setInputs(['name' => 'World']);
    $tools[0]->execute();

    expect($tools[0]->getResult())->toBe('Hello, World!');
});

it('maps property types correctly', function (): void {
    $this->registry->register(
        'typed_tool',
        fn (): string => 'ok',
        'Typed tool',
        [
            ['name' => 'str_param', 'type' => 'string', 'description' => 'A string'],
            ['name' => 'int_param', 'type' => 'integer', 'description' => 'An integer'],
            ['name' => 'bool_param', 'type' => 'boolean', 'description' => 'A boolean'],
            ['name' => 'num_param', 'type' => 'number', 'description' => 'A number'],
            ['name' => 'arr_param', 'type' => 'array', 'description' => 'An array'],
            ['name' => 'obj_param', 'type' => 'object', 'description' => 'An object'],
            ['name' => 'unknown_param', 'type' => 'custom', 'description' => 'Defaults to string'],
        ],
    );

    $tools = $this->registry->getAllNeuronTools();
    $properties = $tools[0]->getProperties();
    $json_properties = array_map(fn ($p) => $p->jsonSerialize(), $properties);

    expect($json_properties[0]['type'])->toBe('string')
        ->and($json_properties[1]['type'])->toBe('integer')
        ->and($json_properties[2]['type'])->toBe('boolean')
        ->and($json_properties[3]['type'])->toBe('number')
        ->and($json_properties[4]['type'])->toBe('array')
        ->and($json_properties[5]['type'])->toBe('object')
        ->and($json_properties[6]['type'])->toBe('string');
});

it('registers tools with custom risk levels', function (): void {
    $this->registry->register('safe', static fn (): string => 'ok', 'Safe', [], 'low');
    $this->registry->register('risky', static fn (): string => 'ok', 'Risky', [], 'high');

    expect($this->registry->getTool('safe')->riskLevel)->toBe('low')
        ->and($this->registry->getTool('risky')->riskLevel)->toBe('high');
});

it('overwrites tool when registering same name twice', function (): void {
    $this->registry->register('tool', static fn (): string => 'v1', 'Version 1', []);
    $this->registry->register('tool', static fn (): string => 'v2', 'Version 2', []);

    $tool = $this->registry->getTool('tool');
    expect($tool->description)->toBe('Version 2');

    $callable = $tool->handler;
    expect($callable())->toBe('v2');
});

it('getAllNeuronToolsWithApproval assigns direct handler for low-risk tools', function (): void {
    $handler = fn (string $query): string => "Result: {$query}";
    $this->registry->register(
        'low_risk_tool',
        $handler,
        'Low risk search',
        [['name' => 'query', 'type' => 'string', 'description' => 'Query']],
        'low',
    );

    $user = User::factory()->create();
    $conversation = Mockery::mock(Conversation::class);
    $conversation->shouldReceive('getAttribute')->with('user')->andReturn($user);

    $actionService = Mockery::mock(ActionRequestService::class);
    $actionService->shouldNotReceive('createRequest');

    $riskClassifier = Mockery::mock(RiskClassifier::class);
    $riskClassifier->shouldReceive('classifyRisk')
        ->with('low_risk_tool', [], null)
        ->andReturn('low');

    config()->set('ai.features.tools.definitions.low_risk_tool.risk_level');

    $pending_requests = [];
    $tools = $this->registry->getAllNeuronToolsWithApproval(
        $conversation,
        $actionService,
        $riskClassifier,
        $pending_requests,
    );

    expect($tools)->toHaveCount(1)
        ->and($tools[0])->toBeInstanceOf(Tool::class)
        ->and($tools[0]->getName())->toBe('low_risk_tool')
        ->and($pending_requests)->toBeEmpty();

    $tools[0]->setInputs(['query' => 'test']);
    $tools[0]->execute();
    expect($tools[0]->getResult())->toBe('Result: test');
});

it('getAllNeuronToolsWithApproval wraps medium-risk tools with ActionRequest flow', function (): void {
    $this->registry->register(
        'medium_tool',
        fn (string $input): string => $input,
        'Medium risk',
        [['name' => 'input', 'type' => 'string', 'description' => 'Input']],
        'medium',
    );

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'title' => 'Test',
    ]);
    $conversation->setRelation('user', $user);

    $actionService = new ActionRequestService(
        $this->registry,
        new RiskClassifier(['medium_tool' => ['risk_level' => 'medium']]),
    );

    $riskClassifier = new RiskClassifier(['medium_tool' => ['risk_level' => 'medium']]);
    config()->set('ai.features.tools.definitions.medium_tool.risk_level', 'medium');

    $pending_requests = [];
    $tools = $this->registry->getAllNeuronToolsWithApproval(
        $conversation,
        $actionService,
        $riskClassifier,
        $pending_requests,
    );

    expect($tools)->toHaveCount(1)
        ->and($pending_requests)->toBeEmpty();

    $tools[0]->setInputs(['input' => 'value']);
    $tools[0]->execute();

    expect($pending_requests)->toHaveCount(1)
        ->and($pending_requests[0]->tool_name)->toBe('medium_tool')
        ->and($pending_requests[0]->tool_args)->toBe(['input' => 'value'])
        ->and($tools[0]->getResult())->toContain('pending user confirmation');
});

it('getAllNeuronToolsWithApproval returns high-risk pending admin approval message', function (): void {
    $this->registry->register(
        'high_tool',
        fn (): string => 'done',
        'High risk delete',
        [],
        'high',
    );

    $user = User::factory()->create();
    $conversation = Conversation::query()->create([
        'user_id' => $user->id,
        'title' => 'Test',
    ]);
    $conversation->setRelation('user', $user);

    $actionService = new ActionRequestService(
        $this->registry,
        new RiskClassifier(['high_tool' => ['risk_level' => 'high']]),
    );

    $riskClassifier = new RiskClassifier(['high_tool' => ['risk_level' => 'high']]);
    config()->set('ai.features.tools.definitions.high_tool.risk_level', 'high');

    $pending_requests = [];
    $tools = $this->registry->getAllNeuronToolsWithApproval(
        $conversation,
        $actionService,
        $riskClassifier,
        $pending_requests,
    );

    $tools[0]->execute();

    expect($pending_requests)->toHaveCount(1)
        ->and($tools[0]->getResult())->toContain('pending admin approval');
});
