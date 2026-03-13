<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

/**
 * DTO for a registered tool (name, description, parameters, risk level, handler).
 *
 * @phpstan-type ParameterShape array{name: string, type: string, description: string, required?: bool}
 */
final readonly class ToolDefinition
{
    /**
     * @param  ParameterShape[]  $parameters
     * @param  callable(mixed ...$args): mixed  $handler
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
        public string $riskLevel,
        public mixed $handler,
    ) {}
}
