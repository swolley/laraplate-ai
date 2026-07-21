<?php

declare(strict_types=1);

namespace Modules\AI\Providers;

use Modules\AI\Contracts\IChatService;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Contracts\ITranslatableModelClassNames;
use Modules\AI\Services\ApplicationContent\ApplicationContentToolProvider;
use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;
use Modules\AI\Services\Assistance\Contracts\AssistantTenantResolverInterface;
use Modules\AI\Services\Assistance\Contracts\InAppAssistanceServiceInterface;
use Modules\AI\Services\Assistance\GlobalAssistantTenantResolver;
use Modules\AI\Services\Assistance\InAppAssistanceService;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCatalog;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCompiler;
use Modules\AI\Services\ChatService;
use Modules\AI\Services\CrossEncoderService;
use Modules\AI\Services\DiscoveryTranslatableModelClassNames;
use Modules\AI\Services\Documentation\Chunking\SplitterFactory;
use Modules\AI\Services\EmbeddingService;
use Modules\AI\Services\LlmQueryIntentParser;
use Modules\AI\Services\SearchEmbedder;
use Modules\AI\Services\SearchOrchestratorAgent;
use Modules\AI\Services\Tools\CompositeContextualToolProvider;
use Modules\AI\Services\Tools\ContextualToolProviderInterface;
use Modules\AI\Services\Tools\GraphToolProvider;
use Modules\Core\Overrides\ModuleServiceProvider;
use Modules\Core\Search\Contracts\IQueryIntentParser;
use Modules\Core\Search\Contracts\IReranker;
use Modules\Core\Search\Contracts\ISearchPlanner;
use Modules\Core\Search\Contracts\ITextEmbedder;
use NeuronAI\RAG\Splitter\SplitterInterface;
use Override;

class AIServiceProvider extends ModuleServiceProvider
{
    #[Override]
    protected string $name = 'AI';

    #[Override]
    protected string $nameLower = 'ai';

    #[Override]
    public function register(): void
    {
        parent::register();

        $this->app->singleton(IChatService::class, ChatService::class);
        $this->app->singleton(IEmbeddingService::class, EmbeddingService::class);
        $this->app->singleton(ITranslatableModelClassNames::class, DiscoveryTranslatableModelClassNames::class);
        $this->app->bind(GraphToolProvider::class);
        $this->app->bind(ApplicationContentToolProvider::class);
        $this->app->bind(
            ContextualToolProviderInterface::class,
            static fn ($app): ContextualToolProviderInterface => new CompositeContextualToolProvider([
                $app->make(GraphToolProvider::class),
                $app->make(ApplicationContentToolProvider::class),
            ]),
        );
        $this->app->singleton(AssistantTenantResolverInterface::class, GlobalAssistantTenantResolver::class);
        $this->app->bind(InAppAssistanceServiceInterface::class, InAppAssistanceService::class);
        $this->app->singleton(
            AssistanceGuardrailPipeline::class,
            static fn (): AssistanceGuardrailPipeline => AssistanceGuardrailPipeline::defaults(),
        );
        $this->app->singleton(
            AssistantPolicyCatalog::class,
            static fn (): AssistantPolicyCatalog => AssistantPolicyCatalog::defaults(),
        );
        $this->app->singleton(
            AssistantPolicyCompiler::class,
            static fn ($app): AssistantPolicyCompiler => new AssistantPolicyCompiler(
                $app->make(AssistantPolicyCatalog::class),
            ),
        );

        $this->app->bind(SplitterInterface::class, static fn (): SplitterInterface => SplitterFactory::make());
    }

    #[Override]
    public function boot(): void
    {
        parent::boot();

        $this->registerSearchBindings();
    }

    /**
     * Override Core search contract bindings with AI-powered implementations.
     */
    private function registerSearchBindings(): void
    {
        if (! config('ai.features.search_orchestration.enabled', true)) {
            return;
        }

        $this->app->singleton(IReranker::class, CrossEncoderService::class);
        $this->app->singleton(ISearchPlanner::class, SearchOrchestratorAgent::class);
        $this->app->singleton(IQueryIntentParser::class, LlmQueryIntentParser::class);
        $this->app->singleton(ITextEmbedder::class, SearchEmbedder::class);
    }
}
