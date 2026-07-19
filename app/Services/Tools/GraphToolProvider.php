<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use Illuminate\Http\Request;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\Core\Graph\Contracts\GraphToolGatewayInterface;
use Modules\Core\Graph\Data\GraphExpandToolInput;
use Modules\Core\Graph\Data\GraphSearchToolInput;
use Modules\Core\Graph\Data\GraphStatsToolInput;
use Throwable;

final readonly class GraphToolProvider implements ContextualToolProviderInterface
{
    public function __construct(
        private GraphToolGatewayInterface $gateway,
        private Request $request,
    ) {}

    public function tools(AssistantAccessContext $context): array
    {
        if ($context->profile !== AssistantProfile::InAppAssistance) {
            return [];
        }

        return [
            new ToolDefinition(
                name: 'graph_search',
                description: 'Search authorized application records and return a bounded read-only relation graph.',
                parameters: $this->searchParameters(),
                riskLevel: 'low',
                handler: fn (mixed $module, mixed $entity, mixed $query, mixed $relations, mixed $depth, mixed $limit, mixed $relation_limit): array => $this->invoke(
                    $context,
                    fn (): array => $this->gateway->search(new GraphSearchToolInput(
                        $this->string($module, 64),
                        $this->string($entity, 64),
                        $this->string($query, 500),
                        $this->stringList($relations),
                        $this->integer($depth),
                        $this->integer($limit),
                        $this->integer($relation_limit),
                    )),
                ),
            ),
            new ToolDefinition(
                name: 'graph_expand',
                description: 'Expand explicitly requested relations from one authorized application record.',
                parameters: $this->centerParameters(),
                riskLevel: 'low',
                handler: fn (mixed $module, mixed $entity, mixed $record_key, mixed $relations, mixed $depth, mixed $limit, mixed $relation_limit): array => $this->invoke(
                    $context,
                    fn (): array => $this->gateway->expand(new GraphExpandToolInput(
                        $this->string($module, 64),
                        $this->string($entity, 64),
                        $this->recordKey($record_key),
                        $this->stringList($relations),
                        $this->integer($depth),
                        $this->integer($limit),
                        $this->integer($relation_limit),
                    )),
                ),
            ),
            new ToolDefinition(
                name: 'graph_stats',
                description: 'Calculate bounded statistics for explicitly requested authorized record relations.',
                parameters: $this->centerParameters(),
                riskLevel: 'low',
                handler: fn (mixed $module, mixed $entity, mixed $record_key, mixed $relations, mixed $depth, mixed $limit, mixed $relation_limit): array => $this->invoke(
                    $context,
                    fn (): array => $this->gateway->stats(new GraphStatsToolInput(
                        $this->string($module, 64),
                        $this->string($entity, 64),
                        $this->recordKey($record_key),
                        $this->stringList($relations),
                        $this->integer($depth),
                        $this->integer($limit),
                        $this->integer($relation_limit),
                    )),
                ),
            ),
        ];
    }

    /**
     * @param  callable(): array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function invoke(AssistantAccessContext $context, callable $operation): array
    {
        try {
            $userKey = $this->request->user()?->getAuthIdentifier();

            if ($userKey === null || trim((string) $userKey) !== $context->userId) {
                return $this->unavailable();
            }

            return $operation();
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchParameters(): array
    {
        return [
            $this->stringParameter('module', 'Installed application module key.', 64),
            $this->stringParameter('entity', 'Application entity key.', 64),
            $this->stringParameter('query', 'Natural-language record search query.', 500),
            $this->relationsParameter(0),
            $this->integerParameter('depth', 'Maximum relation depth.', 1, 2),
            $this->integerParameter('limit', 'Maximum search result count.', 1, 10),
            $this->integerParameter('relation_limit', 'Maximum records per relation.', 1, 10),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function centerParameters(): array
    {
        return [
            $this->stringParameter('module', 'Installed application module key.', 64),
            $this->stringParameter('entity', 'Application entity key.', 64),
            $this->stringParameter('record_key', 'Opaque application record key.', 255),
            $this->relationsParameter(1),
            $this->integerParameter('depth', 'Maximum relation depth.', 1, 2),
            $this->integerParameter('limit', 'Maximum graph node count.', 1, 25),
            $this->integerParameter('relation_limit', 'Maximum records per relation.', 1, 10),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stringParameter(string $name, string $description, int $maximumLength): array
    {
        return [
            'name' => $name,
            'type' => 'string',
            'description' => $description,
            'required' => true,
            'minLength' => 1,
            'maxLength' => $maximumLength,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function integerParameter(string $name, string $description, int $minimum, int $maximum): array
    {
        return [
            'name' => $name,
            'type' => 'integer',
            'description' => $description,
            'required' => true,
            'minimum' => $minimum,
            'maximum' => $maximum,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function relationsParameter(int $minimumItems): array
    {
        return [
            'name' => 'relations',
            'type' => 'array',
            'description' => 'Explicit relation paths to traverse.',
            'required' => true,
            'minItems' => $minimumItems,
            'maxItems' => 10,
            'items' => [
                'type' => 'string',
                'maxLength' => 128,
                'pattern' => '^[A-Za-z_][A-Za-z0-9_]*(\\.[A-Za-z_][A-Za-z0-9_]*)*$',
            ],
        ];
    }

    private function string(mixed $value, int $maximumLength): string
    {
        if (! is_string($value) || mb_strlen($value) > $maximumLength) {
            throw new \InvalidArgumentException('Graph tool string argument is invalid.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 10) {
            throw new \InvalidArgumentException('Graph tool relations argument is invalid.');
        }

        foreach ($value as $item) {
            if (! is_string($item) || mb_strlen($item) > 128) {
                throw new \InvalidArgumentException('Graph tool relation is invalid.');
            }
        }

        return $value;
    }

    private function integer(mixed $value): int
    {
        if (! is_int($value)) {
            throw new \InvalidArgumentException('Graph tool integer argument is invalid.');
        }

        return $value;
    }

    private function recordKey(mixed $value): int|string
    {
        if ((! is_int($value) && ! is_string($value))
            || (is_string($value) && mb_strlen($value) > 255)) {
            throw new \InvalidArgumentException('Graph tool record key is invalid.');
        }

        return $value;
    }

    /**
     * @return array{available: false, nodes: array{}, edges: array{}, truncated: false}
     */
    private function unavailable(): array
    {
        return [
            'available' => false,
            'nodes' => [],
            'edges' => [],
            'truncated' => false,
        ];
    }
}
