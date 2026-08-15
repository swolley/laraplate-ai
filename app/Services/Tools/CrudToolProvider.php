<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use function user_class;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\Core\Casts\CrudRequestData;
use Modules\Core\Casts\DetailRequestData;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Casts\ModifyRequestData;
use Modules\Core\Casts\SearchRequestData;
use Modules\Core\Http\Requests\DetailRequest;
use Modules\Core\Http\Requests\ListRequest;
use Modules\Core\Http\Requests\ModifyRequest;
use Modules\Core\Http\Requests\PendingApprovalsRequest;
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
 * its own. A tool is exposed only for an operation the user is actually
 * permitted to perform: if the user cannot do it, no tool is offered (there is
 * no "escalate to approval" path). Approval, when it happens, belongs to the
 * model: entities using {@see \Modules\Core\Models\Concerns\HasApprovals}
 * capture writes as pending modifications on save unless the writer holds the
 * `approve` credit — that moderation is applied by Core inside the write, not
 * by this provider. All exposed tools therefore run inline.
 */
final readonly class CrudToolProvider implements ContextualToolProviderInterface
{
    private const array VALID_OPERATIONS = ['view', 'list', 'detail', 'search', 'summarize', 'create', 'update', 'delete', 'pending_approvals', 'approve', 'disapprove'];

    /**
     * Upper bound on rows materialized for an in-memory aggregation, so a
     * `summarize` call can never load an unbounded result set. When the true
     * total exceeds this cap the response is flagged as truncated.
     */
    private const int SUMMARIZE_ROW_CAP = 5000;

    /**
     * Aggregation functions supported by the `summarize` tool.
     */
    private const array SUMMARIZE_FUNCTIONS = ['sum', 'avg', 'min', 'max'];

    /**
     * Maps a tool operation to the CRUD permission ability it needs.
     */
    private const array ABILITY = [
        'view' => 'select',
        'list' => 'select',
        'detail' => 'select',
        'search' => 'select',
        'summarize' => 'select',
        'create' => 'insert',
        'update' => 'update',
        'delete' => 'forceDelete',
        'pending_approvals' => 'approve',
        'approve' => 'approve',
        'disapprove' => 'approve',
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

            $model = $this->resolveModel($module, $entity);

            if (! $model instanceof Model) {
                continue;
            }

            foreach ($operations as $operation) {
                if (! $this->userCan($model, self::ABILITY[$operation])) {
                    continue;
                }

                $tools[] = $this->buildTool($module, $entity, (string) $operation);
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

    private function buildTool(string $module, string $entity, string $operation): ToolDefinition
    {
        $name = sprintf('crud_%s_%s_%s', $operation, mb_strtolower($module), mb_strtolower($entity));
        $label = mb_strtolower($module) . '.' . mb_strtolower($entity);

        return new ToolDefinition(
            name: $name,
            description: $this->describe($operation, $label),
            parameters: $this->parameters($operation),
            riskLevel: 'low',
            handler: $this->handlerFor($operation, $module, $entity),
        );
    }

    private function resolveModel(string $module, string $entity): ?Model
    {
        try {
            return DynamicEntity::resolve($entity, request: $this->request, module: $module);
        } catch (Throwable) {
            return null;
        }
    }

    private function userCan(Model $model, string $ability): bool
    {
        try {
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
            'view' => "Propose a {$label} table view (filters/sort) for the UI to apply and load itself. Does not fetch data — use this when the user wants to filter the on-screen table.",
            'list' => "List {$label} records the current user may read.",
            'detail' => "Fetch a single {$label} record by id.",
            'search' => "Full-text search {$label} records.",
            'summarize' => "Aggregate {$label} records: group by one or more columns and compute a count plus optional sum/avg/min/max metrics. Honours the same structured filters as list.",
            'create' => "Create a {$label} record. On moderated entities the change is captured for approval instead of applied immediately.",
            'update' => "Update a {$label} record by id. On moderated entities the change is captured for approval instead of applied immediately.",
            'delete' => "Delete a {$label} record by id. On moderated entities the change is captured for approval instead of applied immediately.",
            'pending_approvals' => "List pending {$label} changes awaiting approval, each with its author; optionally filter by author (name, email or user id).",
            'approve' => "Approve the pending change on a {$label} record by id.",
            'disapprove' => "Reject the pending change on a {$label} record by id.",
            default => "Operate on {$label}.",
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parameters(string $operation): array
    {
        $filters = ['name' => 'filters', 'type' => 'array', 'required' => false, 'items' => ['type' => 'object'], 'description' => 'Structured filters combined with AND. Each item is {property, operator, value}; operator is one of =, !=, >, >=, <, <=, like, in, between (in/between take an array value). Nested groups {operator: and|or, filters: [...]} are allowed.'];
        $sort = ['name' => 'sort', 'type' => 'array', 'required' => false, 'items' => ['type' => 'object'], 'description' => 'Sort order. Each item is {property, direction} where direction is asc or desc.'];
        $limit = ['name' => 'limit', 'type' => 'integer', 'description' => 'Maximum rows to return.', 'required' => false];

        $page = ['name' => 'page', 'type' => 'integer', 'description' => 'Page number (1-based).', 'required' => false];

        return match ($operation) {
            'view', 'list' => [
                $filters,
                $sort,
                $limit,
                $page,
            ],
            'search' => [
                ['name' => 'query', 'type' => 'string', 'description' => 'Text to search for.', 'required' => true],
                $filters,
                $sort,
                $limit,
            ],
            'summarize' => [
                $filters,
                ['name' => 'group_by', 'type' => 'array', 'required' => false, 'items' => ['type' => 'string'], 'description' => 'Column names to group by. Omit for a single overall bucket.'],
                ['name' => 'metrics', 'type' => 'array', 'required' => false, 'items' => ['type' => 'object'], 'description' => 'Numeric metrics per group. Each item is {property, function} where function is one of sum, avg, min, max. A per-group count is always returned.'],
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
            'pending_approvals' => [
                ['name' => 'author', 'type' => 'string', 'description' => 'Optional filter: modifier name, email, or user id.', 'required' => false],
            ],
            'approve', 'disapprove' => [
                ['name' => 'id', 'type' => 'string', 'description' => 'Record identifier.', 'required' => true],
            ],
            default => [],
        };
    }

    /**
     * Named-argument handler matching the tool's parameter names (NeuronAI
     * invokes tools with named arguments, as GraphToolProvider does).
     */
    private function handlerFor(string $operation, string $module, string $entity): callable
    {
        return match ($operation) {
            'view' => fn (mixed $filters = null, mixed $sort = null, mixed $limit = null, mixed $page = null): array => $this->runView($module, $entity, $filters, $sort, $limit, $page),
            'list' => fn (mixed $filters = null, mixed $sort = null, mixed $limit = null, mixed $page = null): array => $this->runList($module, $entity, $filters, $sort, $limit, $page),
            'search' => fn (mixed $query = null, mixed $filters = null, mixed $sort = null, mixed $limit = null): array => $this->runSearch($module, $entity, $query, $filters, $sort, $limit),
            'summarize' => fn (mixed $filters = null, mixed $group_by = null, mixed $metrics = null): array => $this->runSummarize($module, $entity, $filters, $group_by, $metrics),
            'detail' => fn (mixed $id = null): array => $this->runDetail($module, $entity, $id),
            'create' => fn (mixed $attributes = null): array => $this->runCreate($module, $entity, $attributes),
            'update' => fn (mixed $id = null, mixed $attributes = null): array => $this->runUpdate($module, $entity, $id, $attributes),
            'delete' => fn (mixed $id = null): array => $this->runDelete($module, $entity, $id),
            'pending_approvals' => fn (mixed $author = null): array => $this->runPendingApprovals($module, $entity, $author),
            'approve' => fn (mixed $id = null): array => $this->runApproval($module, $entity, $id, 'approve'),
            'disapprove' => fn (mixed $id = null): array => $this->runApproval($module, $entity, $id, 'disapprove'),
            default => static fn (): array => ['error' => 'Unsupported operation.'],
        };
    }

    /**
     * Configure mode: return the filter/sort spec for the UI to apply and load
     * itself. No data is fetched. The frontend's own load enforces the ACL.
     *
     * @return array<string, mixed>
     */
    private function runView(string $module, string $entity, mixed $filters, mixed $sort, mixed $limit, mixed $page): array
    {
        return [
            'apply' => true,
            'request' => [
                'verb' => 'view',
                'module' => $module,
                'entity' => $entity,
                'filters' => $this->normalizeFilters($filters),
                'sort' => $this->normalizeSort($sort),
                'page' => $this->toInt($page),
                'limit' => $this->toInt($limit),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runList(string $module, string $entity, mixed $filters, mixed $sort, mixed $limit, mixed $page): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);
        $normalizedSort = $this->normalizeSort($sort);
        $limitInt = $this->toInt($limit);
        $pageInt = $this->toInt($page);

        $request = ['verb' => 'list', 'module' => $module, 'entity' => $entity, 'filters' => $normalizedFilters, 'sort' => $normalizedSort, 'page' => $pageInt, 'limit' => $limitInt];

        try {
            $validated = [];

            if ($normalizedFilters !== []) {
                $validated['filters'] = $normalizedFilters;
            }

            if ($normalizedSort !== []) {
                $validated['sort'] = $normalizedSort;
            }

            if ($limitInt !== null) {
                $validated['limit'] = $limitInt;
            }

            if ($pageInt !== null) {
                $validated['page'] = $pageInt;
            }

            $data = new ListRequestData($this->makeRequest(ListRequest::class), $entity, $validated, $this->primaryKey($module, $entity), $module);

            return $this->present($this->crud->list($data), $request);
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runSearch(string $module, string $entity, mixed $query, mixed $filters, mixed $sort, mixed $limit): array
    {
        $qs = $this->toString($query);
        $normalizedFilters = $this->normalizeFilters($filters);
        $normalizedSort = $this->normalizeSort($sort);
        $limitInt = $this->toInt($limit);

        $request = ['verb' => 'search', 'module' => $module, 'entity' => $entity, 'query' => $qs, 'filters' => $normalizedFilters, 'sort' => $normalizedSort, 'limit' => $limitInt];

        try {
            $validated = ['qs' => $qs];

            if ($normalizedFilters !== []) {
                $validated['filters'] = $normalizedFilters;
            }

            if ($normalizedSort !== []) {
                $validated['sort'] = $normalizedSort;
            }

            if ($limitInt !== null) {
                $validated['limit'] = $limitInt;
            }

            $data = new SearchRequestData($this->makeRequest(SearchRequest::class), $entity, $validated, $this->primaryKey($module, $entity), $module);

            return $this->present($this->crud->search($data), $request);
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * Group-by aggregation. Delegates to CrudService::list (which enforces the
     * acting user's permission and ACL), materializes up to SUMMARIZE_ROW_CAP
     * rows, then computes per-group count plus the requested numeric metrics in
     * memory. The true total is reported and the result is flagged truncated
     * when it exceeds the cap.
     *
     * @return array<string, mixed>
     */
    private function runSummarize(string $module, string $entity, mixed $filters, mixed $groupBy, mixed $metrics): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);
        $groups = $this->normalizeStringList($groupBy);
        $metricSpecs = $this->normalizeMetrics($metrics);

        $request = ['verb' => 'summarize', 'module' => $module, 'entity' => $entity, 'filters' => $normalizedFilters, 'group_by' => $groups, 'metrics' => $metricSpecs];

        try {
            $validated = ['pagination' => self::SUMMARIZE_ROW_CAP, 'page' => 1];

            if ($normalizedFilters !== []) {
                $validated['filters'] = $normalizedFilters;
            }

            $data = new ListRequestData($this->makeRequest(ListRequest::class), $entity, $validated, $this->primaryKey($module, $entity), $module);
            $result = $this->crud->list($data);

            $rows = $result->data;
            $rowList = $rows instanceof Collection || $rows instanceof EloquentCollection ? $rows->all() : [];
            $buckets = $this->aggregateRows($rowList, $groups, $metricSpecs);

            $aggregatedRows = count($rowList);
            $totalRecords = $result->meta?->totalRecords ?? $aggregatedRows;

            return [
                'request' => $request,
                'data' => $buckets,
                'meta' => [
                    'total_records' => $totalRecords,
                    'aggregated_rows' => $aggregatedRows,
                    'truncated' => $totalRecords > $aggregatedRows,
                ],
            ];
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * @param  list<mixed>  $rows
     * @param  list<string>  $groups
     * @param  list<array{property: string, function: string}>  $metrics
     * @return list<array<string, mixed>>
     */
    private function aggregateRows(array $rows, array $groups, array $metrics): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $key = $this->bucketKey($row, $groups);

            if (! isset($buckets[$key])) {
                $buckets[$key] = ['group' => $this->bucketGroup($row, $groups), 'count' => 0, '_metrics' => []];
            }

            $buckets[$key]['count']++;

            foreach ($metrics as $metric) {
                $value = $this->numericValue($this->rowValue($row, $metric['property']));

                if ($value === null) {
                    continue;
                }

                $label = sprintf('%s(%s)', $metric['function'], $metric['property']);
                $buckets[$key]['_metrics'][$label] = $this->foldMetric(
                    $buckets[$key]['_metrics'][$label] ?? null,
                    $metric['function'],
                    $value,
                );
            }
        }

        return array_values(array_map(fn (array $bucket): array => [
            'group' => $bucket['group'],
            'count' => $bucket['count'],
            'metrics' => $this->finalizeMetrics($bucket['_metrics']),
        ], $buckets));
    }

    /**
     * @param  array{sum: float, n: int, min: float, max: float}|null  $acc
     * @return array{sum: float, n: int, min: float, max: float}
     */
    private function foldMetric(?array $acc, string $function, float $value): array
    {
        if ($acc === null) {
            return ['sum' => $value, 'n' => 1, 'min' => $value, 'max' => $value];
        }

        return [
            'sum' => $acc['sum'] + $value,
            'n' => $acc['n'] + 1,
            'min' => min($acc['min'], $value),
            'max' => max($acc['max'], $value),
        ];
    }

    /**
     * @param  array<string, array{sum: float, n: int, min: float, max: float}>  $metrics
     * @return array<string, float|int>
     */
    private function finalizeMetrics(array $metrics): array
    {
        $out = [];

        foreach ($metrics as $label => $acc) {
            $function = (string) mb_strstr($label, '(', true);
            $out[$label] = match ($function) {
                'sum' => $acc['sum'],
                'avg' => $acc['n'] > 0 ? $acc['sum'] / $acc['n'] : 0,
                'min' => $acc['min'],
                'max' => $acc['max'],
                default => $acc['sum'],
            };
        }

        return $out;
    }

    /**
     * @param  list<string>  $groups
     */
    private function bucketKey(mixed $row, array $groups): string
    {
        if ($groups === []) {
            return '*';
        }

        return implode('||', array_map(
            fn (string $group): string => (string) json_encode($this->rowValue($row, $group)),
            $groups,
        ));
    }

    /**
     * @param  list<string>  $groups
     * @return array<string, mixed>
     */
    private function bucketGroup(mixed $row, array $groups): array
    {
        $group = [];

        foreach ($groups as $property) {
            $group[$property] = $this->rowValue($row, $property);
        }

        return $group;
    }

    private function rowValue(mixed $row, string $property): mixed
    {
        if ($row instanceof Model) {
            return $row->getAttribute($property);
        }

        if (is_array($row)) {
            return $row[$property] ?? null;
        }

        return null;
    }

    private function numericValue(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function runDetail(string $module, string $entity, mixed $id): array
    {
        $key = $this->primaryKey($module, $entity);
        $recordId = $this->toString($id);
        $request = ['verb' => 'detail', 'module' => $module, 'entity' => $entity, 'id' => $recordId];

        try {
            $data = new DetailRequestData($this->makeRequest(DetailRequest::class, [$key => $recordId]), $entity, [], $key, $module);

            return $this->present($this->crud->detail($data), $request);
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runCreate(string $module, string $entity, mixed $attributes): array
    {
        $request = ['verb' => 'create', 'module' => $module, 'entity' => $entity];

        try {
            $data = $this->modifyData($module, $entity, $this->toArray($attributes));

            return $this->present($this->crud->insert($data), $request);
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runUpdate(string $module, string $entity, mixed $id, mixed $attributes): array
    {
        $key = $this->primaryKey($module, $entity);
        $recordId = $this->toString($id);
        $request = ['verb' => 'update', 'module' => $module, 'entity' => $entity, 'id' => $recordId];

        try {
            $data = $this->modifyData($module, $entity, [$key => $recordId] + $this->toArray($attributes));

            return $this->present($this->crud->update($data), $request);
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runDelete(string $module, string $entity, mixed $id): array
    {
        $key = $this->primaryKey($module, $entity);
        $recordId = $this->toString($id);
        $request = ['verb' => 'delete', 'module' => $module, 'entity' => $entity, 'id' => $recordId];

        try {
            $data = $this->modifyData($module, $entity, [$key => $recordId]);

            return $this->present($this->crud->delete($data), $request);
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runPendingApprovals(string $module, string $entity, mixed $author): array
    {
        $authorFilter = $this->toString($author);
        $request = ['verb' => 'pending_approvals', 'module' => $module, 'entity' => $entity, 'author' => $authorFilter === '' ? null : $authorFilter];

        try {
            $data = new CrudRequestData($this->makeRequest(PendingApprovalsRequest::class), $entity, [], $this->primaryKey($module, $entity), $module);
            $result = $this->crud->pendingApprovals($data);

            return ['request' => $request, 'data' => $this->enrichApprovals($result->data, $authorFilter)];
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runApproval(string $module, string $entity, mixed $id, string $operation): array
    {
        $key = $this->primaryKey($module, $entity);
        $recordId = $this->toString($id);
        $request = ['verb' => $operation, 'module' => $module, 'entity' => $entity, 'id' => $recordId];

        try {
            $data = $this->modifyData($module, $entity, [$key => $recordId]);
            $result = $operation === 'approve' ? $this->crud->approve($data) : $this->crud->disapprove($data);

            return $this->present($result, $request);
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * Turn pending-approval rows into arrays, add the modifier's display name,
     * and optionally keep only those authored by the given user (name/email/id).
     *
     * @return list<array<string, mixed>>
     */
    private function enrichApprovals(mixed $rows, string $author): array
    {
        if (! $rows instanceof Collection && ! $rows instanceof EloquentCollection) {
            return [];
        }

        $authorIds = $author === '' ? null : $this->resolveAuthorIds($author);

        return $rows
            ->map(fn (mixed $row): array => $this->approvalRow($row))
            ->filter(static fn (array $row): bool => $authorIds === null || in_array($row['modifier_id'] ?? null, $authorIds, true))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function approvalRow(mixed $row): array
    {
        $data = $row instanceof Fluent ? $row->toArray() : (array) $row;
        $data['modifier_name'] = $this->modifierName($data['modifier_id'] ?? null, $data['modifier_type'] ?? null);

        return $data;
    }

    /**
     * @return list<int>
     */
    private function resolveAuthorIds(string $author): array
    {
        $ids = is_numeric($author) ? [(int) $author] : [];

        try {
            $matched = user_class()::query()
                ->where('name', 'like', "%{$author}%")
                ->orWhere('email', 'like', "%{$author}%")
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $ids = array_merge($ids, $matched);
        } catch (Throwable) {
            // Fall through to whatever numeric id was provided.
        }

        return array_values(array_unique($ids));
    }

    private function modifierName(mixed $id, mixed $type): ?string
    {
        if ($id === null) {
            return null;
        }

        $class = is_string($type) && class_exists($type) ? $type : user_class();

        try {
            $name = $class::query()->whereKey($id)->value('name');

            return is_string($name) ? $name : null;
        } catch (Throwable) {
            return null;
        }
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
     * @param  array<string, mixed>  $routeParameters
     */
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
     * Present a CrudResult alongside the structured request that produced it, so
     * the frontend can reapply the same filters/sort/pagination to its tables.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function present(CrudResult $result, array $request): array
    {
        $data = $result->data;

        $payload = match (true) {
            $data instanceof Model => $data->toArray(),
            $data instanceof EloquentCollection, $data instanceof Collection => $data->toArray(),
            is_scalar($data) => ['value' => $data],
            default => (array) $data,
        };

        $out = ['request' => $request, 'data' => $payload];

        if ($result->meta !== null) {
            $out['meta'] = ['total_records' => $result->meta->totalRecords, 'current_page' => $result->meta->currentPage];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function fail(array $request, Throwable $exception): array
    {
        return ['request' => $request, 'error' => $exception->getMessage()];
    }

    /**
     * Keep only well-formed filter nodes; the shape is passed through unchanged
     * so the same structure round-trips to the frontend.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeFilters(mixed $filters): array
    {
        if (! is_array($filters)) {
            return [];
        }

        return array_values(array_filter($filters, static fn (mixed $node): bool => is_array($node)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeSort(mixed $sort): array
    {
        if (! is_array($sort)) {
            return [];
        }

        return array_values(array_filter(
            $sort,
            static fn (mixed $node): bool => is_array($node) && isset($node['property']) && is_string($node['property']),
        ));
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * Keep only well-formed metric specs {property, function} with a supported
     * aggregation function.
     *
     * @return list<array{property: string, function: string}>
     */
    private function normalizeMetrics(mixed $metrics): array
    {
        if (! is_array($metrics)) {
            return [];
        }

        $normalized = [];

        foreach ($metrics as $metric) {
            if (! is_array($metric) || ! isset($metric['property']) || ! is_scalar($metric['property'])) {
                continue;
            }

            $function = isset($metric['function']) && is_scalar($metric['function'])
                ? mb_strtolower((string) $metric['function'])
                : 'sum';

            if (! in_array($function, self::SUMMARIZE_FUNCTIONS, true)) {
                continue;
            }

            $normalized[] = ['property' => (string) $metric['property'], 'function' => $function];
        }

        return $normalized;
    }

    private function toInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
