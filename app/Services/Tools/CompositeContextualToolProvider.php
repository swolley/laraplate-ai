<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use LogicException;
use Modules\AI\Services\ApplicationContent\ApplicationContentToolProvider;
use Modules\AI\Services\ApplicationContent\Data\ApplicationContentRequestContext;
use Modules\AI\Services\Assistance\AssistantAccessContext;

final readonly class CompositeContextualToolProvider implements ContextualToolProviderInterface
{
    /**
     * @param  list<ContextualToolProviderInterface>  $providers
     */
    public function __construct(private array $providers) {}

    public function tools(AssistantAccessContext $context): array
    {
        return $this->toolsForRequest($context, '');
    }

    /**
     * @return list<ToolDefinition>
     */
    public function toolsForRequest(
        AssistantAccessContext $context,
        string $userQuery,
        ?ApplicationContentRequestContext $requestContext = null,
    ): array {
        $tools = [];

        foreach ($this->providers as $provider) {
            $definitions = $provider instanceof ApplicationContentToolProvider
                ? $provider->toolsForRequest($context, $userQuery, $requestContext)
                : $provider->tools($context);

            foreach ($definitions as $tool) {
                if (isset($tools[$tool->name])) {
                    throw new LogicException('A contextual tool is registered more than once.');
                }

                $tools[$tool->name] = $tool;
            }
        }

        return array_values($tools);
    }
}
