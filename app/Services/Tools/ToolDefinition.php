<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

/**
 * DTO for a registered tool (name, description, parameters, risk level, handler).
 *
 * @phpstan-type ParameterShape array{name: string, type: string, description: string, required?: bool, enum?: list<mixed>, minimum?: int|float, maximum?: int|float, minLength?: int, maxLength?: int, minItems?: int, maxItems?: int, items?: array<string, mixed>}
 */
final readonly class ToolDefinition
{
    /**
     * @param  ParameterShape[]  $parameters
     * @param  callable(mixed ...$args): mixed  $handler
     * @param  int|null  $maxRuns  how many times the model may call the tool in one turn; null leaves Neuron's default
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
        public string $riskLevel,
        public mixed $handler,
        public ?int $maxRuns = null,
    ) {}
}
