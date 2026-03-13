<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Services\EmbeddingService;
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

    $embeddingService = Mockery::mock(EmbeddingService::class);
    $embeddingService->shouldNotReceive('embedDocument');

    $job = new GenerateEmbeddingsJob($model);
    $job->handle($embeddingService);
});

it('returns early when prepareDataToEmbed returns empty string', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');
    $model->shouldReceive('prepareDataToEmbed')->andReturn('');

    $embeddingService = Mockery::mock(EmbeddingService::class);
    $embeddingService->shouldNotReceive('embedDocument');

    $job = new GenerateEmbeddingsJob($model);
    $job->handle($embeddingService);
});

it('processes embeddings and creates records', function (): void {
    $embeddingRelation = Mockery::mock();
    $embeddingRelation->shouldReceive('create')
        ->once()
        ->with(['embedding' => [0.1, 0.2]])
        ->andReturn(null);

    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');
    $model->shouldReceive('prepareDataToEmbed')->andReturn('Some text to embed');
    $model->shouldReceive('embeddings')->andReturn($embeddingRelation);

    $document = new Document('');
    $document->embedding = [0.1, 0.2];

    $embeddingService = Mockery::mock(EmbeddingService::class);
    $embeddingService->shouldReceive('embedDocument')
        ->once()
        ->with('Some text to embed')
        ->andReturn([$document]);

    $job = new GenerateEmbeddingsJob($model);
    $job->handle($embeddingService);
});

it('failed logs error', function (): void {
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
