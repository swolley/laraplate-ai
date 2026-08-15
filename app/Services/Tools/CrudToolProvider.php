<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\Core\Casts\DetailRequestData;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Casts\ModifyRequestData;
use Modules\Core\Casts\SearchRequestData;
use Modules\Core\Http\Requests\DetailRequest;
use Modules\Core\Http\Requests\ListRequest;
use Modules\Core\Http\Requests\ModifyRequest;
use Modules\Core\Http\Requests\SearchRequest;
use Modules\Core\Models\DynamicEntity;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Crud\DTOs\CrudResult;
use Throwable;

/**
 * Exposes Core generic-CRUD operations as AI tools for the in-app assistant,
 * gated by an opt-in per-entity allowlist (`ai.features.tools.crud.entities`).
 *
 * Every call is delegated to {@see CrudService}, which enforces the acting
 * user's permission and row-level ACL — this provider adds no data access of
 * its own. Reads (list/detail/search) run inline. For writes the approval
 * requirement is permission-driven: a user who already holds the write
 * permission for the entity gets a low-risk (inline) tool, while a user who
 * lacks it gets a high-risk tool routed through admin approval. Approval is
 * therefore required only when the user could not perform the write directly.
 */
final readonly class CrudToolProvider implements ContextualToolProviderInterface
{
    private const array READ_OPERATIONS = ['list', 'detail', 'search'];

    private const array VALID_OPERATIONS = ['list', 'detail', 'search', 'create', 'update', 'delete'];

    /**
     * Maps a tool write operation to the CRUD permission ability it needs.
     */
    private const array WRITE_ABILITY = [
        'create' => 'insert',
        'update' => 'update',
        'delete' => 'forceDelete',
    ];

    public function __construct(
        private CrudService $crud,
        private AuthorizationService $authorization,
        private Request $request,
    ) {}

    public function tools(AssistantAccessContext $context): array
    {
        if ($context->profile !== AssistantProfile::InAppAssistance) {
            return [];
        }

        if (! $this->actingUserMatches($context)) {
            return [];
        }

        $tools = [];

        foreach ($this->allowlist() as $entityKey => $operations) {
            [$module, $entity] = $this->splitEntityKey((string) $entityKey);

            if ($module === null) {
                continue;
            }

            foreach ($operations as $operation) {
                $definition = $this->buildTool($module, $entity, (string) $operation);

                if ($definition instanceof ToolDefinition) {
                    $tools[] = $definition;
                }
            }
        }

        return $tools;
    }

    /**
     * @return array<string, list<string>>
     */
    private function allowlist(): array
    {
        $entities = config('ai.features.tools.crud.entities', []);

        if (! is_array($entities)) {
            return [];
        }

        $normalized = [];

        foreach ($entities as $key => $operations) {
            if (! is_string($key) || ! is_array($operations)) {
                continue;
            }

            $normalized[$key] = array_values(array_filter(
                array_map(static fn (mixed $op): string => mb_strtolower((string) $op), $operations),
                static fn (string $op): bool => in_array($op, self::VALID_OPERATIONS, true),
            ));
        }

        return $normalized;
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function splitEntityKey(string $key): array
    {
        if (! str_contains($key, '.')) {
            return [null, $key];
        }

        [$module, $entity] = explode('.', $key, 2);

        return [$module === '' ? null : $module, $entity];
    }

    private function actingUserMatches(AssistantAccessContext $context): bool
    {
        $identifier = $this->request->user()?->getAuthIdentifier();

        return $identifier !== null
            && $context->userId !== null
            && $context->userId === (string) $identifier;
    }

    private function buildTool(string $module, string $entity, string $operation): ?ToolDefinition
    {
        if (! in_array($operation, self::VALID_OPERATIONS, true)) {
            return null;
        }

        $name = sprintf('crud_%s_%s_%s', $operation, mb_strtolower($module), mb_strtolower($entity));
        $label = mb_strtolower($module) . '.' . mb_strtolower($entity);

        return new ToolDefinition(
            name: $name,
            description: $this->describe($operation, $label),
            parameters: $this->parameters($operation),
            riskLevel: $this->riskFor($operation, $module, $entity),
            handler: fn (mixed ...$args): array => $this->run($operation, $module, $entity, $args),
        );
    }

    /**
     * Reads are always inline. Writes are inline (low) when the acting user
     * already holds the matching CRUD permission, otherwise gated at high risk
     * so the registry routes them through admin approval.
     */
    private function riskFor(string $operation, string $module, string $entity): string
    {
        if (in_array($operation, self::READ_OPERATIONS, true)) {
            return 'low';
        }

        return $this->userCanWrite($module, $entity, self::WRITE_ABILITY[$operation]) ? 'low' : 'high';
    }

    private function userCanWrite(string $module, string $entity, string $ability): bool
    {
        try {
            $model = DynamicEntity::resolve($entity, request: $this->request, module: $module);

            return $this->authorization->checkPermission(
                $this->request,
                $model->getTable(),
                $ability,
                $model->getConnectionName(),
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function describe(string $operation, string $label): string
    {
        return match ($operation) {
            'list' => "List {$label} records the current user may read.",
            'detail' => "Fetch a single {$label} record by id.",
            'search' => "Full-text search {$label} records.",
            'create' => "Create a {$label} record. Requires approval unless you already hold the create permission.",
            'update' => "Update a {$label} record by id. Requires approval unless you already hold the update permission.",
            'delete' => "Delete a {$label} record by id. Requires approval unless you already hold the delete permission.",
            default => "Operate on {$label}.",
        };
    }

    /**
     * @return list<array{name: string, type: string, description: string, required?: bool}>
     */
    private function parameters(string $operation): array
    {
        return match ($operation) {
            'list' => [
                ['name' => 'limit', 'type' => 'integer', 'description' => 'Maximum rows to return.', 'required' => false],
                ['name' => 'page', 'type' => 'integer', 'description' => 'Page number (1-based).', 'required' => false],
            ],
            'search' => [
                ['name' => 'query', 'type' => 'string', 'description' => 'Text to search for.', 'required' => true],
                ['name' => 'limit', 'type' => 'integer', 'description' => 'Maximum rows to return.', 'required' => false],
            ],
            'detail', 'delete' => [
                ['name' => 'id', 'type' => 'string', 'description' => 'Record identifier.', 'required' => true],
            ],
            'create' => [
                ['name' => 'attributes', 'type' => 'object', 'description' => 'Field values for the new record.', 'required' => true],
            ],
            'update' => [
                ['name' => 'id', 'type' => 'string', 'description' => 'Record identifier.', 'required' => true],
                ['name' => 'attributes', 'type' => 'object', 'description' => 'Field values to change.', 'required' => true],
            ],
            default => [],
        };
    }

    /**
     * @param  list<mixed>  $args
     * @return array<string, mixed>
     */
    private function run(string $operation, string $module, string $entity, array $args): array
    {
        try {
            return match ($operation) {
                'list' => $this->present($this->crud->list($this->listData($module, $entity, $args))),
                'detail' => $this->present($this->crud->detail($this->detailData($module, $entity, $args))),
                'search' => $this->present($this->crud->search($this->searchData($module, $entity, $args))),
                'create' => $this->present($this->crud->insert($this->modifyData($module, $entity, $this->attributes($args)))),
                'update' => $this->present($this->crud->update($this->modifyData($module, $entity, $this->updateChanges($module, $entity, $args)))),
                'delete' => $this->present($this->crud->delete($this->modifyData($module, $entity, [$this->primaryKey($module, $entity) => $this->stringArg($args, 0)]))),
                default => ['error' => 'Unsupported operation.'],
            };
        } catch (Throwable $exception) {
            return ['error' => $exception->getMessage()];
        }
    }

    private function listData(string $module, string $entity, array $args): ListRequestData
    {
        $validated = [];

        if (($limit = $this->intArg($args, 0)) !== null) {
            $validated['limit'] = $limit;
        }

        if (($page = $this->intArg($args, 1)) !== null) {
            $validated['page'] = $page;
        }

        return new ListRequestData(
            $this->makeRequest(ListRequest::class),
            $entity,
            $validated,
            $this->primaryKey($module, $entity),
            $module,
        );
    }

    private function detailData(string $module, string $entity, array $args): DetailRequestData
    {
        $key = $this->primaryKey($module, $entity);
        $id = $this->stringArg($args, 0);

        return new DetailRequestData(
            $this->makeRequest(DetailRequest::class, [$key => $id]),
            $entity,
            [],
            $key,
            $module,
        );
    }

    private function searchData(string $module, string $entity, array $args): SearchRequestData
    {
        $validated = ['qs' => $this->stringArg($args, 0)];

        if (($limit = $this->intArg($args, 1)) !== null) {
            $validated['limit'] = $limit;
        }

        return new SearchRequestData(
            $this->makeRequest(SearchRequest::class),
            $entity,
            $validated,
            $this->primaryKey($module, $entity),
            $module,
        );
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function modifyData(string $module, string $entity, array $changes): ModifyRequestData
    {
        return new ModifyRequestData(
            $this->makeRequest(ModifyRequest::class),
            $entity,
            $changes,
            $this->primaryKey($module, $entity),
            $module,
        );
    }

    /**
     * @param  list<mixed>  $args
     * @return array<string, mixed>
     */
    private function attributes(array $args): array
    {
        $attributes = $args[0] ?? [];

        return is_array($attributes) ? $attributes : [];
    }

    /**
     * @param  list<mixed>  $args
     * @return array<string, mixed>
     */
    private function updateChanges(string $module, string $entity, array $args): array
    {
        return [$this->primaryKey($module, $entity) => $this->stringArg($args, 0)] + $this->attributes([$args[1] ?? []]);
    }

    private function makeRequest(string $requestClass, array $routeParameters = []): Request
    {
        /** @var Request $request */
        $request = $requestClass::create('/app/ai/crud-tool', 'POST');
        $request->setUserResolver(fn (): mixed => $this->request->user());

        if ($routeParameters !== []) {
            $request->merge($routeParameters);
            $request->setRouteResolver(static fn (): object => new class($routeParameters)
            {
                /**
                 * @param  array<string, mixed>  $parameters
                 */
                public function __construct(private array $parameters) {}

                public function parameter(string $name): mixed
                {
                    return $this->parameters[$name] ?? null;
                }
            });
        }

        return $request;
    }

    private function primaryKey(string $module, string $entity): string
    {
        $key = DynamicEntity::resolve($entity, request: $this->request, module: $module)->getKeyName();

        return is_array($key) ? (string) head($key) : $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CrudResult $result): array
    {
        $data = $result->data;

        $payload = match (true) {
            $data instanceof Model => $data->toArray(),
            $data instanceof EloquentCollection, $data instanceof Collection => $data->toArray(),
            is_scalar($data) => ['value' => $data],
            default => (array) $data,
        };

        return ['data' => $payload];
    }

    /**
     * @param  list<mixed>  $args
     */
    private function stringArg(array $args, int $index): string
    {
        $value = $args[$index] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  list<mixed>  $args
     */
    private function intArg(array $args, int $index): ?int
    {
        $value = $args[$index] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
