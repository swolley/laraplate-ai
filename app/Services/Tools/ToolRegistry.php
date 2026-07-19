<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use Modules\AI\Models\ActionRequest;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\ActionRequestService;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\Core\Models\User;
use NeuronAI\Tools\PropertyType;

use function ai_config_nullable_string;
use NeuronAI\Tools\Tool;

/**
 * Registry for AI tools that can be called by LLM.
 *
 * Tools are registered with handlers and risk levels. Converts internal
 * ToolDefinition DTOs into NeuronAI Tool instances for agent consumption.
 */
final class ToolRegistry
{
    /**
     * @var array<string, ToolDefinition>
     */
    private array $tools = [];

    /**
     * Register a tool with its handler.
     *
     * @param  array{name: string, type: string, description: string, required?: bool, enum?: list<mixed>, minimum?: int|float, maximum?: int|float, minLength?: int, maxLength?: int, minItems?: int, maxItems?: int, items?: array<string, mixed>}[]  $parameters
     * @param  callable(mixed ...$args): mixed  $handler
     */
    public function register(
        string $name,
        callable $handler,
        string $description,
        array $parameters,
        string $riskLevel = 'low',
    ): void {
        $this->tools[$name] = new ToolDefinition(
            name: $name,
            description: $description,
            parameters: $parameters,
            riskLevel: $riskLevel,
            handler: $handler,
        );
    }

    public function getTool(string $name): ?ToolDefinition
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return ToolDefinition[]
     */
    public function getAllTools(): array
    {
        return array_values($this->tools);
    }

    public function hasTools(): bool
    {
        return $this->tools !== [];
    }

    /**
     * Build request-local tools without adding them to the global action registry.
     *
     * @return list<Tool>
     */
    public function getContextualNeuronTools(
        ContextualToolProviderInterface $provider,
        AssistantAccessContext $context,
    ): array {
        return array_map(
            fn (ToolDefinition $definition): Tool => $this->buildNeuronTool($definition),
            $provider->tools($context),
        );
    }

    /**
     * Build NeuronAI Tool instances for agent attachment.
     *
     * @return Tool[]
     */
    public function getAllNeuronTools(): array
    {
        $result = [];

        foreach ($this->tools as $definition) {
            $result[] = $this->buildNeuronTool($definition);
        }

        return $result;
    }

    /**
     * Build NeuronAI Tool instances with risk-based approval wrapping.
     * Low-risk tools execute immediately. Medium/high-risk tools create ActionRequests.
     *
     * @param  ActionRequest[]  $pending_requests  Collects ActionRequests created for medium/high-risk tools (passed by reference)
     * @return Tool[]
     */
    public function getAllNeuronToolsWithApproval(
        Conversation $conversation,
        ActionRequestService $action_request_service,
        RiskClassifier $risk_classifier,
        array &$pending_requests,
    ): array {
        $result = [];

        foreach ($this->tools as $definition) {
            $tool = $this->buildNeuronToolStructure($definition);

            $config_risk = ai_config_nullable_string("ai.features.tools.definitions.{$definition->name}.risk_level");
            $risk_level = $risk_classifier->classifyRisk($definition->name, [], $config_risk);

            if ($risk_level === 'low') {
                $tool->setCallable($definition->handler);
            } else {
                $tool->setCallable(function (...$args) use ($definition, $conversation, $action_request_service, $risk_level, &$pending_requests): string {
                    $named_args = [];

                    foreach ($definition->parameters as $index => $param) {
                        $name = $param['name'];

                        // @codeCoverageIgnoreStart
                        if (array_key_exists($name, $args)) {
                            $named_args[$name] = $args[$name];
                        } elseif (array_key_exists($index, $args)) {
                            $named_args[$name] = $args[$index];
                        } else {
                            $named_args[$name] = null;
                        }
                        // @codeCoverageIgnoreEnd
                    }

                    $user = $conversation->user;

                    if (! $user instanceof User) {
                        return "Action '{$definition->name}' requires an authenticated user.";
                    }

                    $request = $action_request_service->createRequest(
                        $user,
                        $definition->name,
                        $named_args,
                        $conversation,
                    );

                    $pending_requests[] = $request;

                    $status_label = $risk_level === 'medium'
                        ? 'pending user confirmation'
                        : 'pending admin approval';

                    return "Action '{$definition->name}' requires {$status_label} before execution. Request ID: {$request->id}";
                });
            }

            $result[] = $tool;
        }

        return $result;
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
