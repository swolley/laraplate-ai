<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance;

use function ai_config_bool;

use InvalidArgumentException;
use Modules\AI\Ai\Providers\ProviderFactory;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCompiler;

/**
 * What the in-app assistant offers an instance, as a client may learn it before it shows any
 * assistant surface. Nothing here depends on the user or on what the request carries.
 */
final readonly class AssistantCapabilities
{
    public const string PROPOSALS_CAPABILITY = 'ui_proposals';

    public function __construct(private AssistantPolicyCompiler $policy_compiler) {}

    /**
     * The assistant's reason to exist is to answer from the user documentation, so its one global
     * switch is the one of the FAQ/RAG answers (`features.faq.enabled`).
     */
    public function enabled(): bool
    {
        return ai_config_bool('ai.features.faq.enabled', true);
    }

    /**
     * The chat provider chosen in Settings has what it needs to be called: a key, or a URL.
     */
    public function configured(): bool
    {
        return ProviderFactory::isConfigured();
    }

    /**
     * Proposals are offered when the policy compiled for the in-app profile with the capability
     * `ui_proposals` still allows a tool: the capability only holds the proposal tools, so an empty
     * result means that the catalog lacks the capability or that the profile does not grant it.
     */
    public function proposals(): bool
    {
        try {
            $policy = $this->policy_compiler->compile(AssistantProfile::InAppAssistance, [self::PROPOSALS_CAPABILITY]);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $policy->allowedTools !== [];
    }

    /**
     * No client-facing streaming agent endpoint exists yet.
     */
    public function streaming(): bool
    {
        return false;
    }

    /**
     * @return array{enabled: bool, configured: bool, features: array{proposals: bool, streaming: bool}}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled(),
            'configured' => $this->configured(),
            'features' => [
                'proposals' => $this->proposals(),
                'streaming' => $this->streaming(),
            ],
        ];
    }
}
