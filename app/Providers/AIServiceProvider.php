<?php

declare(strict_types=1);

namespace Modules\AI\Providers;

use Modules\AI\Contracts\IChatService;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Services\ChatService;
use Modules\AI\Services\EmbeddingService;
use Modules\AI\Services\CrossEncoderService;
use Modules\AI\Services\LlmQueryIntentParser;
use Modules\AI\Services\SearchEmbedder;
use Modules\AI\Services\SearchOrchestratorAgent;
use Modules\Core\Overrides\ModuleServiceProvider;
use Modules\Core\Search\Contracts\IQueryIntentParser;
use Modules\Core\Search\Contracts\IReranker;
use Modules\Core\Search\Contracts\ISearchPlanner;
use Modules\Core\Search\Contracts\ITextEmbedder;
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
