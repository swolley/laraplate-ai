<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use Modules\AI\Services\Assistance\AssistantAccessContext;

interface ContextualToolProviderInterface
{
    /**
     * @return list<ToolDefinition>
     */
    public function tools(AssistantAccessContext $context): array;
}
