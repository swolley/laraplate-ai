<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

final class RiskClassifier
{
    /**
     * @var array<string, array{risk_level?: string}>
     */
    private array $tool_definitions = [];

    /**
     * @param  array<string, array{risk_level?: string}>|null  $tool_definitions
     */
    public function __construct(?array $tool_definitions = null)
    {
        $this->tool_definitions = $tool_definitions ?? $this->loadToolDefinitions();
    }

    /**
     * Classify risk for a tool call (low, medium, high).
     *
     * @param  array<string, mixed>  $args
     *
     * Priority:
     * 1. Explicit config_risk parameter (from ToolDefinition)
     * 2. Config override in ai.features.tools.definitions
     * 3. Heuristic based on tool name
     */
    public function classifyRisk(string $tool_name, array $args, ?string $config_risk = null): string
    {
        $resolved = $this->resolveConfiguredRisk($config_risk);
        if ($resolved !== null) {
            return $resolved;
        }

        $configured = $this->tool_definitions[$tool_name]['risk_level'] ?? null;
        $configured_string = is_string($configured) ? $configured : null;
        $resolved = $this->resolveConfiguredRisk($configured_string);
        if ($resolved !== null) {
            return $resolved;
        }

        return $this->heuristicRisk($tool_name);
    }

    /**
     * @return array<string, array{risk_level?: string}>
     */
    private function loadToolDefinitions(): array
    {
        $definitions = config('ai.features.tools.definitions', []);

        if (! is_array($definitions)) {
            return [];
        }

        /** @var array<string, array{risk_level?: string}> $definitions */
        return $definitions;
    }

    /**
     * Normalize deployment / override risk labels. Unknown values are ignored so heuristics can apply.
     *
     * @return 'low'|'medium'|'high'|'unknown'|null null when the label should be ignored
     */
    private function resolveConfiguredRisk(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (in_array($value, ['low', 'medium', 'high'], true)) {
            return $value;
        }

        if ($value === 'unknown') {
            return 'unknown';
        }

        return null;
    }

    private function heuristicRisk(string $tool_name): string
    {
        $lower = mb_strtolower($tool_name);

        if (str_contains($lower, 'delete') || str_contains($lower, 'remove') || str_contains($lower, 'destroy')) {
            return 'high';
        }

        if (str_contains($lower, 'update') || str_contains($lower, 'edit') || str_contains($lower, 'create') || str_contains($lower, 'insert')) {
            return 'medium';
        }

        return 'low';
    }
}
