<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Policies;

final readonly class CompiledAssistantPolicy
{
    /**
     * @param  list<string>  $allowedTools  exact names or trailing-wildcard patterns
     * @param  list<string>  $deniedTools  exact names or trailing-wildcard patterns; they override allowed ones
     */
    public function __construct(
        public string $version,
        public string $systemPrompt,
        public array $allowedCorpora,
        public array $allowedTools,
        public array $allowedFields,
        public array $deniedTools = [],
    ) {}

    public function allowsTool(string $name): bool
    {
        return ToolNameMatcher::allows($name, $this->allowedTools, $this->deniedTools);
    }
}
