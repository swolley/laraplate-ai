<?php

declare(strict_types=1);

namespace Modules\AI\Data\Search;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * The structured output the model is asked for when it plans a search: the strategy and the settings of
 * each stage. Every key has a default, so an answer that leaves a section out still deserializes;
 * `SearchOrchestratorAgent` clamps whatever comes back to the ranges the search accepts.
 */
final class SearchPlanData
{
    #[SchemaProperty(description: 'The retrieval strategy: fulltext, vector or hybrid.')]
    public string $strategy = 'hybrid';

    #[SchemaProperty(description: 'Which retrievals run and how many candidates they return.')]
    public SearchRetrievalPlan $retrieval;

    #[SchemaProperty(description: 'How the results of the retrievals are merged.')]
    public SearchEnsemblePlan $ensemble;

    #[SchemaProperty(description: 'How the merged results are ranked.')]
    public SearchRankingPlan $ranking;

    #[SchemaProperty(description: 'The part of the vector search in the final score.')]
    public SearchVectorPlan $vector;

    #[SchemaProperty(description: 'The filters the query asks for.')]
    public SearchFilters $filters;

    #[SchemaProperty(description: 'When a search that scored badly is run again.')]
    public SearchRetryPolicy $retry_policy;

    /**
     * Neuron builds the object without it and calls it afterwards: it fills the sections that the answer left out.
     */
    public function __construct()
    {
        $this->retrieval ??= new SearchRetrievalPlan;
        $this->ensemble ??= new SearchEnsemblePlan;
        $this->ranking ??= new SearchRankingPlan;
        $this->vector ??= new SearchVectorPlan;
        $this->filters ??= new SearchFilters;
        $this->retry_policy ??= new SearchRetryPolicy;
    }
}
