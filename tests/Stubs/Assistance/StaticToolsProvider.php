<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Assistance;

use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Tools\ContextualToolProviderInterface;
use Modules\AI\Services\Tools\ToolDefinition;
use Override;

/**
 * A tool provider that offers the same definitions to every request, none by default: the provider
 * of a test that is not about tools, or that needs a tool it can name.
 */
final readonly class StaticToolsProvider implements ContextualToolProviderInterface
{
    /**
     * @param  list<ToolDefinition>  $tools
     */
    public function __construct(private array $tools = []) {}

    #[Override]
    public function tools(AssistantAccessContext $context): array
    {
        return $this->tools;
    }
}
