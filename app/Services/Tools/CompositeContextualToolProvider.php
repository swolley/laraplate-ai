<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use LogicException;
use Modules\AI\Services\Assistance\AssistantAccessContext;

final readonly class CompositeContextualToolProvider implements ContextualToolProviderInterface
{
    /**
     * @param  list<ContextualToolProviderInterface>  $providers
     */
    public function __construct(private array $providers) {}

    public function tools(AssistantAccessContext $context): array
    {
        $tools = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->tools($context) as $tool) {
                if (isset($tools[$tool->name])) {
                    throw new LogicException('A contextual tool is registered more than once.');
                }

                $tools[$tool->name] = $tool;
            }
        }

        return array_values($tools);
    }
}
