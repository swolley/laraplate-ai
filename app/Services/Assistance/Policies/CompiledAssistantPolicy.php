<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Policies;

final readonly class CompiledAssistantPolicy
{
    public function __construct(
        public string $version,
        public string $systemPrompt,
        public array $allowedCorpora,
        public array $allowedTools,
        public array $allowedFields,
    ) {}
}
