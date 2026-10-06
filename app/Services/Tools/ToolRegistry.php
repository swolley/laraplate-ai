<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use Modules\AI\Services\Assistance\AssistantAccessContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;

/**
 * Converts request-local ToolDefinition DTOs, built by the contextual tool providers, into
 * NeuronAI Tool instances for agent consumption. It holds no tools of its own.
 */
final class ToolRegistry
{
    /**
     * Build request-local tools without adding them to the global action registry.
     *
     * @return list<Tool>
     */
    public function getContextualNeuronTools(
        ContextualToolProviderInterface $provider,
        AssistantAccessContext $context,
        ?array $allowedToolNames = null,
    ): array {
        $definitions = $provider->tools($context);

        if ($allowedToolNames !== null) {
            $definitions = array_values(array_filter(
                $definitions,
                static fn (ToolDefinition $definition): bool => in_array($definition->name, $allowedToolNames, true),
            ));
        }

        return array_map(
            fn (ToolDefinition $definition): Tool => $this->buildNeuronTool($definition),
            $definitions,
        );
    }

    /**
     * @param  list<ToolDefinition>  $definitions
     * @param  list<string>|null  $allowedToolNames
     * @return list<Tool>
     */
    public function getNeuronToolsForDefinitions(array $definitions, ?array $allowedToolNames = null): array
    {
        if ($allowedToolNames !== null) {
            $definitions = array_values(array_filter(
                $definitions,
                static fn (ToolDefinition $definition): bool => in_array($definition->name, $allowedToolNames, true),
            ));
        }

        return array_map(
            fn (ToolDefinition $definition): Tool => $this->buildNeuronTool($definition),
            $definitions,
        );
    }

    private function buildNeuronTool(ToolDefinition $definition): Tool
    {
        $tool = $this->buildNeuronToolStructure($definition);
        $tool->setCallable($definition->handler);

        return $tool;
    }

    private function buildNeuronToolStructure(ToolDefinition $definition): Tool
    {
        $tool = Tool::make(
            $definition->name,
            $definition->description,
        );

        if ($definition->maxRuns !== null) {
            $tool->setMaxRuns($definition->maxRuns);
        }

        foreach ($definition->parameters as $param) {
            $tool->addProperty(
                new SchemaToolProperty(
                    name: $param['name'],
                    type: $this->mapPropertyType($param['type']),
                    description: $param['description'],
                    required: $param['required'] ?? true,
                    enum: $param['enum'] ?? [],
                    constraints: array_intersect_key($param, array_flip([
                        'minimum',
                        'maximum',
                        'minLength',
                        'maxLength',
                        'minItems',
                        'maxItems',
                        'items',
                    ])),
                ),
            );
        }

        return $tool;
    }

    private function mapPropertyType(string $type): PropertyType
    {
        return match ($type) {
            'integer', 'int' => PropertyType::INTEGER,
            'number', 'float', 'double' => PropertyType::NUMBER,
            'boolean', 'bool' => PropertyType::BOOLEAN,
            'array' => PropertyType::ARRAY,
            'object' => PropertyType::OBJECT,
            default => PropertyType::STRING,
        };
    }
}
