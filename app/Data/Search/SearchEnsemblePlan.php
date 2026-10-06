<?php

declare(strict_types=1);

namespace Modules\AI\Data\Search;

use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * How the results of the searches are merged. The defaults are what a missing key means.
 */
final class SearchEnsemblePlan
{
    #[SchemaProperty(description: 'Whether the ensemble merges the searches.')]
    public bool $enabled = true;

    #[SchemaProperty(description: 'Weight of the full text results, from 0 to 1.', min: 0, max: 1)]
    public float $keyword_weight = 0.35;

    #[SchemaProperty(description: 'Weight of the vector results, from 0 to 1.', min: 0, max: 1)]
    public float $vector_weight = 0.35;

    #[SchemaProperty(description: 'Weight of the hybrid results, from 0 to 1.', min: 0, max: 1)]
    public float $hybrid_weight = 0.30;

    #[SchemaProperty(description: 'Boost for a result that more than one search found, from 0 to 1.', min: 0, max: 1)]
    public float $agreement_boost = 0.15;

    #[SchemaProperty(description: 'Reciprocal rank fusion constant (10 to 200).', min: 10, max: 200)]
    public int $rrf_k = 60;

    #[SchemaProperty(description: 'Weight of the reciprocal rank fusion, from 0 to 1.', min: 0, max: 1)]
    public float $rrf_weight = 0.25;
}
