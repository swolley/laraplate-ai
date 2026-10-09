<?php

declare(strict_types=1);

namespace Modules\AI\Search;

use Illuminate\Contracts\Foundation\Application;
use Modules\AI\Services\CrossEncoderService;
use Modules\AI\Services\LlmQueryIntentParser;
use Modules\AI\Services\SearchEmbedder;
use Modules\AI\Services\SearchOrchestratorAgent;
use Modules\Core\Search\Contracts\ISearchStrategyResolver;
use Modules\Core\Search\DTOs\SearchStrategy;
use Modules\Core\Search\Enums\SearchMode;
use Modules\Core\Search\Services\CoreSearchStrategyResolver;
use Override;
use Throwable;

/**
 * Overlays Core's search strategy: `balanced` adds the query embedding, `deep` adds LLM planning and intent
 * parsing and cross-encoder reranking on top. `fast`, and anything that cannot be served, is Core's own strategy.
 *
 * The overlay is chosen per request. Nothing here rebinds a Core contract, so a search that asks for `fast`
 * never builds an AI class.
 */
final readonly class AiSearchStrategyResolver implements ISearchStrategyResolver
{
    public function __construct(
        private CoreSearchStrategyResolver $core,
        private Application $app,
    ) {}

    #[Override]
    public function resolve(SearchMode $mode): SearchStrategy
    {
        $cheap = $this->core->resolve(SearchMode::Fast);

        if ($mode === SearchMode::Fast) {
            return $cheap;
        }

        if (! (bool) config('ai.features.search_orchestration.enabled', true)) {
            return $this->degraded($cheap, 'search_orchestration_disabled');
        }

        try {
            $embedder = $this->app->make(SearchEmbedder::class);
        } catch (Throwable) {
            return $this->degraded($cheap, 'embedder_unavailable');
        }

        if ($mode === SearchMode::Balanced) {
            return new SearchStrategy(
                applied_mode: SearchMode::Balanced,
                planner: $cheap->planner,
                reranker: $cheap->reranker,
                intent_parser: $cheap->intent_parser,
                embedder: $embedder,
            );
        }

        try {
            return new SearchStrategy(
                applied_mode: SearchMode::Deep,
                planner: $this->app->make(SearchOrchestratorAgent::class),
                reranker: $this->app->make(CrossEncoderService::class),
                intent_parser: $this->app->make(LlmQueryIntentParser::class),
                embedder: $embedder,
            );
        } catch (Throwable) {
            return $this->degraded($cheap, 'deep_unavailable');
        }
    }

    private function degraded(SearchStrategy $cheap, string $reason): SearchStrategy
    {
        return new SearchStrategy(
            applied_mode: $cheap->applied_mode,
            planner: $cheap->planner,
            reranker: $cheap->reranker,
            intent_parser: $cheap->intent_parser,
            embedder: $cheap->embedder,
            max_retries: $cheap->max_retries,
            degraded_reason: $reason,
        );
    }
}
