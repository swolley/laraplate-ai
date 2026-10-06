<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Agents;

use Modules\AI\Ai\Providers\AiModelChoice;
use Modules\AI\Ai\Providers\ProviderFactory;
use Modules\AI\Enums\AiModelFeature;
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
        protected ?int $maxOutputTokens = null,
    ) {
        // Agent extends NeuronAI's Workflow, whose constructor initialises the
        // workflow executor. Without this call the executor stays uninitialised
        // and running the agent throws "Workflow::$executor must not be accessed
        // before initialization".
        parent::__construct();
    }

    /**
     * An agent on the provider and model chosen in Settings for this feature, limited to
     * `$maxOutputTokens` of output when given.
     */
    public static function forFeature(AiModelFeature $feature, ?string $systemPrompt = null, ?int $maxOutputTokens = null): static
    {
        $choice = AiModelChoice::forFeature($feature);

        return static::make($choice->provider, $systemPrompt, $choice->model, $maxOutputTokens);
    }

    protected function provider(): AIProviderInterface
    {
        return ProviderFactory::make($this->providerName, $this->model, $this->maxOutputTokens);
    }

    protected function instructions(): string
    {
        return $this->systemPrompt ?? 'You are a helpful AI assistant.';
    }
}
