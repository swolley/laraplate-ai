<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use LLPhant\Chat\FunctionInfo\FunctionInfo;
use LLPhant\Chat\FunctionInfo\Parameter;
use RuntimeException;

/**
 * Registry for AI tools that can be called by LLM.
 *
 * Tools are registered with handlers and risk levels. When LLM proposes a tool call,
 * use generateTextOrReturnFunctionCalled() which returns FunctionInfo[] without executing.
 * Then create ActionRequest based on the returned tool calls.
 */
final class ToolRegistry
{
    /**
     * @var array<string, ToolDefinition>
     */
    private array $tools = [];

    /**
     * Placeholder method - actual execution goes through ActionRequestService.
     * This exists because LLPhant FunctionInfo expects the instance to have callable methods.
     */
    public function __call(string $name, array $arguments): mixed
    {
        // This should never be called directly - tools are executed via ActionRequestService
        throw new RuntimeException("Tool '{$name}' should be executed via ActionRequestService, not directly.");
    }

    /**
     * Register a tool with its handler.
     *
     * @param  array{name: string, type: string, description: string}[]  $parameters
     * @param  callable(mixed ...$args): mixed  $handler  The actual handler to execute
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

    /**
     * Check if any tools are registered.
     */
    public function hasTools(): bool
    {
        return $this->tools !== [];
    }

    /**
     * Build LLPhant FunctionInfo[] for chat tools.
     * These are used with setTools() on chat instance.
     *
     * Note: LLPhant FunctionInfo requires an instance with methods, but we use
     * generateTextOrReturnFunctionCalled() which returns FunctionInfo[] without
     * executing them. The actual execution happens through ActionRequestService.
     *
     * @return FunctionInfo[]
     */
    public function getAllToolsAsFunctionInfo(): array
    {
        $result = [];

        foreach ($this->tools as $definition) {
            $result[] = $this->buildFunctionInfo($definition);
        }

        return $result;
    }

    /**
     * Parse arguments from FunctionInfo returned by LLPhant.
     *
     * @return array<string, mixed>
     */
    public function parseToolArguments(FunctionInfo $function_info): array
    {
        if (! isset($function_info->jsonArgs) || $function_info->jsonArgs === '') {
            return [];
        }

        return json_decode($function_info->jsonArgs, true, 512, JSON_THROW_ON_ERROR) ?? [];
    }

    private function buildFunctionInfo(ToolDefinition $definition): FunctionInfo
    {
        $params = [];
        $required = [];

        foreach ($definition->parameters as $p) {
            $param = new Parameter(
                $p['name'],
                $p['type'] ?? 'string',
                $p['description'] ?? '',
            );
            $params[] = $param;
            $required[] = $param;
        }

        // LLPhant FunctionInfo needs an instance, but since we use generateTextOrReturnFunctionCalled()
        // which returns without executing, we can use $this as a placeholder.
        // The handler execution goes through ActionRequestService, not through FunctionInfo::call().
        return new FunctionInfo(
            $definition->name,
            $this,
            $definition->description,
            $params,
            $required,
        );
    }
}
