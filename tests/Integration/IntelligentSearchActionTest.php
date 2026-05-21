<?php

declare(strict_types=1);

use Modules\AI\Actions\IntelligentSearchAction;
use Modules\Core\Search\Contracts\IQueryIntentParser;
use Modules\Core\Search\Contracts\IReranker;
use Modules\Core\Search\Contracts\ISearchPlanner;
use Modules\Core\Search\Contracts\ITextEmbedder;
use Modules\Core\Search\Services\EnsembleSearchService;

function createActionWithMocks(
    ?Closure $plannerSetup = null,
    ?Closure $parserSetup = null,
    ?ITextEmbedder $embedder = null,
    ?Closure $ensembleSetup = null,
): IntelligentSearchAction {
    $planner = Mockery::mock(ISearchPlanner::class);
    $parser = Mockery::mock(IQueryIntentParser::class);

    $reranker = Mockery::mock(IReranker::class);
    $ensemble = Mockery::mock(EnsembleSearchService::class, [$reranker]);

    if ($plannerSetup) {
        $plannerSetup($planner);
    }
    if ($parserSetup) {
        $parserSetup($parser);
    }
    if ($ensembleSetup) {
        $ensembleSetup($ensemble);
    }

    return new IntelligentSearchAction($planner, $parser, $embedder, $ensemble);
}

it('can be constructed with all dependencies', function (): void {
    $action = createActionWithMocks();
    expect($action)->toBeInstanceOf(IntelligentSearchAction::class);
});

it('can be constructed with null embedder', function (): void {
    $action = createActionWithMocks(embedder: null);
    expect($action)->toBeInstanceOf(IntelligentSearchAction::class);
});
