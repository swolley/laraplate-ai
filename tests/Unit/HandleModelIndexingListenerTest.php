<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Listeners\HandleModelIndexingListener;
use Modules\AI\Tests\Unit\SearchableModelStub;
use Modules\Core\Events\ModelRequiresIndexing;

beforeEach(function (): void {
    Config::set('ai.features.embeddings.enabled', true);
    Queue::fake();
});

it('does nothing when embeddings feature disabled', function (): void {
    Config::set('ai.features.embeddings.enabled', false);

    $model = new SearchableModelStub;
    $model->id = 1;

    $event = new ModelRequiresIndexing($model, false);
    $listener = new HandleModelIndexingListener();
    $listener->handle($event);

    Queue::assertNothingPushed();
});

it('does nothing when model does not support embeddings', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');

    $event = new ModelRequiresIndexing($model, false);
    $listener = new HandleModelIndexingListener();
    $listener->handle($event);

    Queue::assertNothingPushed();
});

it('dispatches GenerateEmbeddingsJob for async', function (): void {
    $model = new SearchableModelStub;
    $model->id = 1;

    $event = new ModelRequiresIndexing($model, false);
    $listener = new HandleModelIndexingListener();
    $listener->handle($event);

    Queue::assertPushed(GenerateEmbeddingsJob::class);
    expect($event->required_pre_processing)->toContain('embeddings');
});

it('runs GenerateEmbeddingsJob sync when event sync is true', function (): void {
    $model = new SearchableModelStub;
    $model->id = 1;

    $event = new ModelRequiresIndexing($model, true);
    $listener = new HandleModelIndexingListener();
    $listener->handle($event);

    Queue::assertNothingPushed();
    expect($event->required_pre_processing)->toContain('embeddings');
});

it('adds embeddings to required pre-processing', function (): void {
    $model = new SearchableModelStub;
    $model->id = 1;

    $event = new ModelRequiresIndexing($model, false);
    $listener = new HandleModelIndexingListener();
    $listener->handle($event);

    expect($event->required_pre_processing)->toContain('embeddings');
});
