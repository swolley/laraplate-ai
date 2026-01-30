<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

/**
 * DTO for a registered tool (name, description, parameters, risk level, handler).
 *
 * @phpstan-type ParameterShape array{name: string, type: string, description: string}
 */
final class ToolDefinition
{
    /**
     * @param  ParameterShape[]  $parameters
     * @param  callable(mixed ...$args): mixed  $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
        public readonly string $riskLevel,
        public readonly mixed $handler,
    ) {}
}
