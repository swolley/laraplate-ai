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
        $this->tool_definitions = $tool_definitions ?? (array) config('ai.features.tools.definitions', []);
    }

    /**
     * Classify risk for a tool call (low, medium, high).
     *
     * Priority:
     * 1. Explicit config_risk parameter (from ToolDefinition)
     * 2. Config override in ai.features.tools.definitions
     * 3. Heuristic based on tool name
     */
    public function classifyRisk(string $tool_name, array $args, ?string $config_risk = null): string
    {
        if ($config_risk !== null && in_array($config_risk, ['low', 'medium', 'high'], true)) {
            return $config_risk;
        }

        $configured = $this->tool_definitions[$tool_name]['risk_level'] ?? null;

        if ($configured !== null && in_array($configured, ['low', 'medium', 'high'], true)) {
            return $configured;
        }

        return $this->heuristicRisk($tool_name);
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
