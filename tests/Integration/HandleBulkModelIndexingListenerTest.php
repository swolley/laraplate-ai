<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Modules\AI\Listeners\HandleBulkModelIndexingListener;
use Modules\AI\Services\ModelEmbeddingSynchronizer;
use Modules\AI\Tests\Unit\SearchableModelStub;
use Modules\Core\Events\ModelsRequireIndexing;

beforeEach(function (): void {
    Config::set('ai.features.embeddings.enabled', true);
    Config::set('search.vector_search.enabled', true);
});

function bulk_listener_stub(int $id): SearchableModelStub
{
    $model = new SearchableModelStub;
    $model->id = $id;

    return $model;
}

it('batch-embeds only the embeddable models in the chunk', function (): void {
    $embeddableA = bulk_listener_stub(1);
    $embeddableB = bulk_listener_stub(2);
    // Plain model: no Searchable trait, filtered out before it reaches the synchronizer.
    $plain = Mockery::mock(Model::class)->makePartial();

    $synchronizer = Mockery::mock(ModelEmbeddingSynchronizer::class);
    $synchronizer->shouldReceive('sync')
        ->once()
        ->withArgs(fn (Collection $models): bool => $models->count() === 2
            && $models->every(fn (Model $m): bool => $m instanceof SearchableModelStub));

    $listener = new HandleBulkModelIndexingListener($synchronizer);
    $listener->handle(new ModelsRequireIndexing(collect([$embeddableA, $plain, $embeddableB]), true));
});

it('does nothing when the embeddings feature is disabled', function (): void {
    Config::set('ai.features.embeddings.enabled', false);

    $synchronizer = Mockery::mock(ModelEmbeddingSynchronizer::class);
    $synchronizer->shouldNotReceive('sync');

    $listener = new HandleBulkModelIndexingListener($synchronizer);
    $listener->handle(new ModelsRequireIndexing(collect([bulk_listener_stub(1)]), true));
});

it('does nothing when no model in the chunk is embeddable', function (): void {
    Config::set('search.vector_search.enabled', false);

    $synchronizer = Mockery::mock(ModelEmbeddingSynchronizer::class);
    $synchronizer->shouldNotReceive('sync');

    $listener = new HandleBulkModelIndexingListener($synchronizer);
    $listener->handle(new ModelsRequireIndexing(collect([bulk_listener_stub(1), bulk_listener_stub(2)]), true));
});
