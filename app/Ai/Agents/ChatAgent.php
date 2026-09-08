<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Agents;

use Modules\AI\Ai\Providers\ProviderFactory;
use NeuronAI\Agent\Agent;
use NeuronAI\Providers\AIProviderInterface;

/**
 * General-purpose chat agent powered by NeuronAI.
 * Supports multi-provider configuration via ProviderFactory.
 */
class ChatAgent extends Agent
{
    public function __construct(
        protected ?string $providerName = null,
        protected ?string $systemPrompt = null,
        protected ?string $model = null,
    ) {
        // Agent extends NeuronAI's Workflow, whose constructor initialises the
        // workflow executor. Without this call the executor stays uninitialised
        // and running the agent throws "Workflow::$executor must not be accessed
        // before initialization".
        parent::__construct();
    }

    public static function make(mixed ...$arguments): static
    {
        /** @phpstan-ignore new.static */
        return new static(...$arguments);
    }

    protected function provider(): AIProviderInterface
    {
        return ProviderFactory::make($this->providerName, $this->model);
    }

    protected function instructions(): string
    {
        return $this->systemPrompt ?? 'You are a helpful AI assistant.';
    }
}
