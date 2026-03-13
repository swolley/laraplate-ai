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
    ) {}

    public static function make(...$arguments): static
    {
        /** @phpstan-ignore new.static */
        return new static(...$arguments);
    }

    protected function provider(): AIProviderInterface
    {
        return ProviderFactory::make($this->providerName);
    }

    protected function instructions(): string
    {
        return $this->systemPrompt ?? 'You are a helpful AI assistant.';
    }
}
