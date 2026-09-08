<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\Core\Events\ModelPreProcessingCompleted;
use NeuronAI\RAG\Document;

beforeEach(function (): void {
    Log::spy();
});

it('has correct properties', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');

    $job = new GenerateEmbeddingsJob($model);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([30, 60, 120])
        ->and($job->timeout)->toBe(300);
});

it('middleware returns ThrottlesExceptions and RateLimited', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');

    $job = new GenerateEmbeddingsJob($model);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(2)
        ->and($middleware[0])->toBeInstanceOf(Illuminate\Queue\Middleware\ThrottlesExceptions::class)
        ->and($middleware[1])->toBeInstanceOf(Illuminate\Queue\Middleware\RateLimited::class);
});

it('returns early when prepareDataToEmbed returns empty', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');
    $model->shouldReceive('prepareDataToEmbed')->andReturn(null);

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldNotReceive('embedDocument');

    $job = new GenerateEmbeddingsJob($model);
    $job->handle($embedding_service);
});

it('returns early when prepareDataToEmbed returns empty string', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');
    $model->shouldReceive('prepareDataToEmbed')->andReturn('');

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldNotReceive('embedDocument');

    $job = new GenerateEmbeddingsJob($model);
    $job->handle($embedding_service);
});

it('replaces existing embeddings then creates records (idempotent regeneration)', function (): void {
    Event::fake([ModelPreProcessingCompleted::class]);

    // A regeneration must delete the model's previous embeddings before creating
    // the fresh set, otherwise retries append duplicate ModelEmbedding rows.
    $embeddingRelation = Mockery::mock();
    $embeddingRelation->shouldReceive('delete')->once();
    $embeddingRelation->shouldReceive('create')
        ->once()
        ->with(['embedding' => [0.1, 0.2]])
        ->andReturn(null);

    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');
    $model->shouldReceive('prepareDataToEmbed')->andReturn('Some text to embed');
    $model->shouldReceive('embeddings')->andReturn($embeddingRelation);
    $model->shouldReceive('fresh')->andReturn($model);

    $document = new Document('');
    $document->embedding = [0.1, 0.2];

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldReceive('embedDocument')
        ->once()
        ->with('Some text to embed')
        ->andReturn([$document]);

    $job = new GenerateEmbeddingsJob($model);
    $job->handle($embedding_service);

    Event::assertDispatched(ModelPreProcessingCompleted::class);
});

it('failed dispatches ModelPreProcessingCompleted so indexing proceeds without the vector', function (): void {
    // On permanent failure the document must still be indexed (keyword-only),
    // so the finalize listener needs the completion signal to fire.
    Event::fake([ModelPreProcessingCompleted::class]);

    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 7;
    $model->shouldReceive('getTable')->andReturn('test');

    $job = new GenerateEmbeddingsJob($model);
    $job->failed(new Exception('boom'));

    Event::assertDispatched(
        ModelPreProcessingCompleted::class,
        fn (ModelPreProcessingCompleted $event): bool => $event->model === $model && $event->processing_type === 'embeddings',
    );
});

it('failed logs error', function (): void {
    Event::fake([ModelPreProcessingCompleted::class]);

    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;

    $job = new GenerateEmbeddingsJob($model);
    $exception = new Exception('Job failed');

    $job->failed($exception);

    Log::shouldHaveReceived('error')
        ->once()
        ->with('GenerateEmbeddingsJob failed', Mockery::on(fn (array $context): bool => isset($context['model'], $context['model_id'], $context['error'])
            && $context['model_id'] === 1
            && $context['error'] === 'Job failed'));
});
