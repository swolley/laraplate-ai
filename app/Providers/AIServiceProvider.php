<?php

declare(strict_types=1);

namespace Modules\AI\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\MediaAnalysis\Contracts\MediaTranscriber;
use Modules\AI\Ai\MediaAnalysis\Contracts\MediaVisionAnalyzer;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisGate;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelRegistry;
use Modules\AI\Ai\MediaAnalysis\Transcription\WhisperTranscriber;
use Modules\AI\Ai\MediaAnalysis\Vision\NeuronVisionAnalyzer;
use Modules\AI\Console\RefreshAiModelsCommand;
use Modules\AI\Console\RepairMissingEmbeddingsCommand;
use Modules\AI\Contracts\IChatService;
use Modules\AI\Contracts\IEmbeddableModels;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Contracts\ITranslatableModelClassNames;
use Modules\AI\Filament\MediaAnalysisSchemaContributor;
use Modules\AI\Observers\MediaAnalysisRefcountObserver;
use Modules\AI\Search\MediaAnalysisSearchContributor;
use Modules\AI\Services\ApplicationContent\ApplicationContentCitationMapper;
use Modules\AI\Services\ApplicationContent\ApplicationContentToolProvider;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationService;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentRetrievalStrategyEvaluationService;
use Modules\AI\Services\ApplicationContent\Evaluation\Contracts\PerStrategyEngineRetrieverInterface;
use Modules\AI\Services\ApplicationContent\Evaluation\PerStrategyEngineRetriever;
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
use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationService;
use Modules\AI\Services\EmbeddableModels;
use Modules\AI\Services\EmbeddingService;
use Modules\AI\Services\LlmQueryIntentParser;
use Modules\AI\Services\SearchEmbedder;
use Modules\AI\Services\SearchOrchestratorAgent;
use Modules\AI\Services\Tools\CompositeContextualToolProvider;
use Modules\AI\Services\Tools\ContextualToolProviderInterface;
use Modules\AI\Services\Tools\CrudToolProvider;
use Modules\AI\Services\Tools\GraphToolProvider;
use Modules\Core\Filament\ResourceSchemaContributorRegistry;
use Modules\Core\Models\Media;
use Modules\Core\Overrides\ModuleServiceProvider;
use Modules\Core\Search\Contracts\IQueryIntentParser;
use Modules\Core\Search\Contracts\IReranker;
use Modules\Core\Search\Contracts\ISearchPlanner;
use Modules\Core\Search\Contracts\ITextEmbedder;
use Modules\Core\Search\SearchableContributorRegistry;
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
        $this->app->singleton(IEmbeddableModels::class, EmbeddableModels::class);
        $this->app->singleton(EmbeddingModelRegistry::class);

        // Media analysis (M6, M21): the model registry plus the swappable analyzer
        // contracts. Vision is neuron-ai-backed; transcription posts to the self
        // -hosted Whisper service (a no-op when WHISPER_URL is unset).
        $this->app->singleton(MediaAnalysisModelRegistry::class);
        $this->app->bind(MediaVisionAnalyzer::class, NeuronVisionAnalyzer::class);
        $this->app->bind(MediaTranscriber::class, WhisperTranscriber::class);
        $this->app->singleton(ITranslatableModelClassNames::class, DiscoveryTranslatableModelClassNames::class);
        $this->app->bind(GraphToolProvider::class);
        $this->app->bind(CrudToolProvider::class);
        $this->app->scoped(ApplicationContentCitationMapper::class);
        $this->app->bind(ApplicationContentToolProvider::class);
        $this->app->singleton(ApplicationContentEvaluationService::class);
        $this->app->singleton(ApplicationContentRetrievalStrategyEvaluationService::class);
        $this->app->bind(PerStrategyEngineRetrieverInterface::class, PerStrategyEngineRetriever::class);
        $this->app->singleton(DocumentationEvaluationService::class);
        $this->app->bind(
            ContextualToolProviderInterface::class,
            static fn ($app): ContextualToolProviderInterface => new CompositeContextualToolProvider([
                $app->make(GraphToolProvider::class),
                $app->make(CrudToolProvider::class),
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

        // Contribute AI media analysis to the media search document/vector through
        // Core's contributor seam (M4a). No-op until analysis rows exist, so Core
        // stays AI-agnostic and this is safe to register unconditionally.
        $this->app->make(SearchableContributorRegistry::class)
            ->register(new MediaAnalysisSearchContributor());

        // Contribute the AI analysis panel + re-analyze action to the media Filament
        // view through Core's resource-schema seam (M22). Gated by the master switch
        // inside the contributor, so Core stays AI-agnostic.
        $this->app->make(ResourceSchemaContributorRegistry::class)
            ->register(new MediaAnalysisSchemaContributor($this->app->make(MediaAnalysisGate::class)));

        Media::observe(MediaAnalysisRefcountObserver::class);
    }

    #[Override]
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)
                ->command(RefreshAiModelsCommand::class)
                ->dailyAt('03:00')
                ->onOneServer();

            // Heals the records an embedding outage degraded to keyword-only: a job that spent its
            // exception budget indexes the record without a vector and nothing else brings it back.
            // The command probes the service first, so an outage costs nothing, and skips while
            // jobs are queued, so a long backfill is not dispatched twice.
            $this->app->make(Schedule::class)
                ->command(RepairMissingEmbeddingsCommand::class, ['--all', '--if-idle'])
                ->hourly()
                ->withoutOverlapping()
                ->onOneServer();
        });
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
