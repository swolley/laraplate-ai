<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Console\RepairMissingEmbeddingsCommand;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use NeuronAI\RAG\Document;

/**
 * Builds a NeuronAI Document carrying the given embedding vector, the shape
 * IEmbeddingService::embedDocument() returns.
 *
 * @param  list<float>  $vector
 */
function repairEmbeddingDocument(array $vector): Document
{
    $document = new Document('');
    $document->embedding = $vector;

    return $document;
}

/**
 * Stub the single batched embedding call the repair path makes through
 * GenerateEmbeddingsJob -> ModelEmbeddingSynchronizer, returning one chunk
 * document per input text from a text => vector map. An input text absent from
 * the map throws, asserting that a fresh (skipped) record is never embedded.
 *
 * @param  array<string, list<float>>  $map
 */
function stubRepairEmbedBatch($service, array $map): void
{
    $service->shouldReceive('embedDocumentsBatch')
        ->once()
        ->andReturnUsing(static function (array $texts) use ($map): array {
            return array_map(static function (string $text) use ($map): array {
                if (! array_key_exists($text, $map)) {
                    throw new RuntimeException("unexpected embed text: {$text}");
                }

                return [repairEmbeddingDocument($map[$text])];
            }, $texts);
        });
}

/**
 * Runs the repair command via Artisan::call and returns [exitCode, output].
 * Chaining multiple expectsOutputToContain() assertions on $this->artisan()
 * only reliably consumes the first one when several substrings share a
 * single rendered line (see Modules/Core/tests/Feature/Console/RouteCheckCommandTest.php),
 * so multi-substring assertions here capture the raw buffer instead.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function runRepairEmbeddingsCommand(array $parameters): array
{
    $exit_code = Artisan::call('ai:embeddings:repair', $parameters);

    return [$exit_code, Artisan::output()];
}

/**
 * Fakes the /health preflight to report the currently active profile's
 * service_model, i.e. no mismatch. Http::fake() registrations are additive
 * (first URL match wins) rather than replacing, so each test registers its
 * own /health stub instead of sharing one from beforeEach.
 */
function fakeHealthyEmbeddingService(): void
{
    $active_service_model = app(EmbeddingModelRegistry::class)->active()->serviceModel;

    Http::fake([
        '*/health' => Http::response(['model' => $active_service_model]),
    ]);
}

beforeEach(function (): void {
    Config::set('search.vector_search.enabled', true);

    Schema::create('embeddable_test_models', function ($table): void {
        $table->id();
        $table->string('title')->nullable();
    });
});

it('regenerates records missing an embedding and stamps the active model_key', function (): void {
    fakeHealthyEmbeddingService();

    $model = new EmbeddableTestModel(['title' => 'Alpha']);
    $model->saveQuietly();

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    stubRepairEmbedBatch($embedding_service, ['Alpha' => [0.1, 0.2]]);

    app()->instance(IEmbeddingService::class, $embedding_service);

    $this->artisan('ai:embeddings:repair', [
        'model' => EmbeddableTestModel::class,
        '--sync' => true,
    ])->assertSuccessful();

    $rows = $model->embeddings()->get();
    $expected_key = app(EmbeddingModelRegistry::class)->active()->key;

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->model_key)->toBe($expected_key)
        ->and($rows->first()->embedding)->toBe([0.1, 0.2]);
});

it('does not touch records that already carry an embedding when --stale is not set', function (): void {
    fakeHealthyEmbeddingService();

    $model = new EmbeddableTestModel(['title' => 'Beta']);
    $model->saveQuietly();
    $model->embeddings()->create(['embedding' => [0.9, 0.9], 'model_key' => 'legacy-model']);

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldNotReceive('embedDocumentsBatch');
    app()->instance(IEmbeddingService::class, $embedding_service);

    $this->artisan('ai:embeddings:repair', [
        'model' => EmbeddableTestModel::class,
        '--sync' => true,
    ])->assertSuccessful();

    expect($model->embeddings()->count())->toBe(1)
        ->and($model->embeddings()->first()->model_key)->toBe('legacy-model');
});

it('--stale regenerates records whose embeddings carry a non-active model_key, leaving active ones untouched', function (): void {
    fakeHealthyEmbeddingService();

    $active_key = app(EmbeddingModelRegistry::class)->active()->key;

    $stale = new EmbeddableTestModel(['title' => 'Stale content']);
    $stale->saveQuietly();
    $stale->embeddings()->create(['embedding' => [0.1, 0.1], 'model_key' => 'legacy-model']);

    $fresh = new EmbeddableTestModel(['title' => 'Fresh content']);
    $fresh->saveQuietly();
    $fresh->embeddings()->create(['embedding' => [0.5, 0.5], 'model_key' => $active_key]);

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    stubRepairEmbedBatch($embedding_service, ['Stale content' => [0.9, 0.9]]);
    app()->instance(IEmbeddingService::class, $embedding_service);

    $this->artisan('ai:embeddings:repair', [
        'model' => EmbeddableTestModel::class,
        '--sync' => true,
        '--stale' => true,
    ])->assertSuccessful();

    $stale_rows = $stale->embeddings()->get();
    $fresh_rows = $fresh->embeddings()->get();

    expect($stale_rows)->toHaveCount(1)
        ->and($stale_rows->first()->model_key)->toBe($active_key)
        ->and($stale_rows->first()->embedding)->toBe([0.9, 0.9])
        ->and($fresh_rows)->toHaveCount(1)
        ->and($fresh_rows->first()->embedding)->toBe([0.5, 0.5])
        ->and($fresh_rows->first()->model_key)->toBe($active_key);
});

it('warns when the embedding service /health reports a different model than the active profile, but still succeeds', function (): void {
    Http::fake([
        '*/health' => Http::response(['model' => 'some-other-model']),
    ]);

    $model = new EmbeddableTestModel(['title' => 'Gamma']);
    $model->saveQuietly();

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    stubRepairEmbedBatch($embedding_service, ['Gamma' => [0.3, 0.3]]);
    app()->instance(IEmbeddingService::class, $embedding_service);

    $active = app(EmbeddingModelRegistry::class)->active();

    [$exit_code, $output] = runRepairEmbeddingsCommand([
        'model' => EmbeddableTestModel::class,
        '--sync' => true,
    ]);

    expect($exit_code)->toBe(RepairMissingEmbeddingsCommand::SUCCESS)
        ->and($output)->toContain('some-other-model')
        ->and($output)->toContain($active->serviceModel);
});

it('warns but still succeeds when the embedding service /health cannot be reached', function (): void {
    Http::fake([
        '*/health' => Http::response(null, 500),
    ]);

    $model = new EmbeddableTestModel(['title' => 'Delta']);
    $model->saveQuietly();

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    stubRepairEmbedBatch($embedding_service, ['Delta' => [0.4, 0.4]]);
    app()->instance(IEmbeddingService::class, $embedding_service);

    [$exit_code, $output] = runRepairEmbeddingsCommand([
        'model' => EmbeddableTestModel::class,
        '--sync' => true,
    ]);

    expect($exit_code)->toBe(RepairMissingEmbeddingsCommand::SUCCESS)
        ->and($output)->toContain('Could not verify embedding service health');
});
