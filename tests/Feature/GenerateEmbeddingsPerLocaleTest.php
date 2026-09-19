<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Services\ModelEmbeddingSynchronizer;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Modules\AI\Tests\Stubs\TranslatedEmbeddableTestModel;
use Modules\AI\Tests\Stubs\TranslatedEmbeddableTestModelTranslation;
use Modules\Core\Events\ModelPreProcessingCompleted;
use NeuronAI\RAG\Document;

/**
 * Builds a NeuronAI Document carrying the given embedding vector, the shape
 * IEmbeddingService::embedDocument() returns.
 *
 * @param  list<float>  $vector
 */
function perLocaleEmbeddingDocument(array $vector): Document
{
    $document = new Document('');
    $document->embedding = $vector;

    return $document;
}

/**
 * Create a bilingual (it/en) translated model and embed both locales once with
 * the given vectors, returning the model.
 *
 * @param  list<float>  $it
 * @param  list<float>  $en
 */
function makeBilingualEmbeddedModel(array $it = [0.1, 0.1], array $en = [0.2, 0.2]): TranslatedEmbeddableTestModel
{
    $model = new TranslatedEmbeddableTestModel();
    $model->saveQuietly();

    TranslatedEmbeddableTestModelTranslation::query()->create([
        'translated_embeddable_test_model_id' => $model->id,
        'locale' => 'it',
        'title' => 'Titolo italiano',
    ]);
    TranslatedEmbeddableTestModelTranslation::query()->create([
        'translated_embeddable_test_model_id' => $model->id,
        'locale' => 'en',
        'title' => 'English title',
    ]);

    $service = Mockery::mock(IEmbeddingService::class);
    $service->shouldReceive('embedDocument')->once()->with('Titolo italiano')->andReturn([perLocaleEmbeddingDocument($it)]);
    $service->shouldReceive('embedDocument')->once()->with('English title')->andReturn([perLocaleEmbeddingDocument($en)]);

    (new GenerateEmbeddingsJob($model))->handle($service);

    return $model;
}

beforeEach(function (): void {
    Schema::create('embeddable_test_models', function ($table): void {
        $table->id();
        $table->string('title')->nullable();
    });

    Schema::create('translated_embeddable_test_models', function ($table): void {
        $table->id();
    });

    Schema::create('translated_embeddable_test_model_translations', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('translated_embeddable_test_model_id');
        $table->string('locale', 10);
        $table->text('title')->nullable();
    });
});

it('stamps one ModelEmbedding row per locale for a bilingual translated model', function (): void {
    Event::fake([ModelPreProcessingCompleted::class]);

    $model = new TranslatedEmbeddableTestModel();
    $model->saveQuietly();

    TranslatedEmbeddableTestModelTranslation::query()->create([
        'translated_embeddable_test_model_id' => $model->id,
        'locale' => 'it',
        'title' => 'Titolo italiano',
    ]);
    TranslatedEmbeddableTestModelTranslation::query()->create([
        'translated_embeddable_test_model_id' => $model->id,
        'locale' => 'en',
        'title' => 'English title',
    ]);

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldReceive('embedDocument')
        ->once()
        ->with('Titolo italiano')
        ->andReturn([perLocaleEmbeddingDocument([0.1, 0.1])]);
    $embedding_service->shouldReceive('embedDocument')
        ->once()
        ->with('English title')
        ->andReturn([perLocaleEmbeddingDocument([0.2, 0.2])]);

    $job = new GenerateEmbeddingsJob($model);
    $job->handle($embedding_service);

    $rows = $model->embeddings()->get();
    $expected_key = app(EmbeddingModelRegistry::class)->active()->key;

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('model_key')->unique()->all())->toBe([$expected_key])
        ->and($rows->firstWhere('locale', 'it')?->embedding)->toBe([0.1, 0.1])
        ->and($rows->firstWhere('locale', 'en')?->embedding)->toBe([0.2, 0.2]);

    Event::assertDispatched(ModelPreProcessingCompleted::class, fn (ModelPreProcessingCompleted $event): bool => $event->model->is($model) && $event->processing_type === 'embeddings');
});

it('stamps a single locale = null row for a non-translated model', function (): void {
    $model = new EmbeddableTestModel(['title' => 'Plain text']);
    $model->saveQuietly();

    $embedding_service = Mockery::mock(IEmbeddingService::class);
    $embedding_service->shouldReceive('embedDocument')
        ->once()
        ->with('Plain text')
        ->andReturn([perLocaleEmbeddingDocument([0.5, 0.5])]);

    $job = new GenerateEmbeddingsJob($model);
    $job->handle($embedding_service);

    $rows = $model->embeddings()->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->locale)->toBeNull()
        ->and($rows->first()->model_key)->toBe(app(EmbeddingModelRegistry::class)->active()->key);
});

it('regenerates only the requested locale, leaving the other locale untouched', function (): void {
    $model = new TranslatedEmbeddableTestModel();
    $model->saveQuietly();

    TranslatedEmbeddableTestModelTranslation::query()->create([
        'translated_embeddable_test_model_id' => $model->id,
        'locale' => 'it',
        'title' => 'Titolo italiano',
    ]);
    TranslatedEmbeddableTestModelTranslation::query()->create([
        'translated_embeddable_test_model_id' => $model->id,
        'locale' => 'en',
        'title' => 'English title',
    ]);

    $first_service = Mockery::mock(IEmbeddingService::class);
    $first_service->shouldReceive('embedDocument')->once()->with('Titolo italiano')->andReturn([perLocaleEmbeddingDocument([0.1, 0.1])]);
    $first_service->shouldReceive('embedDocument')->once()->with('English title')->andReturn([perLocaleEmbeddingDocument([0.2, 0.2])]);

    $first_job = new GenerateEmbeddingsJob($model);
    $first_job->handle($first_service);

    $en_row_before = $model->embeddings()->get()->firstWhere('locale', 'en');

    // Change the italian translation only, then regenerate just that locale.
    TranslatedEmbeddableTestModelTranslation::query()
        ->where('translated_embeddable_test_model_id', $model->id)
        ->where('locale', 'it')
        ->update(['title' => 'Titolo italiano aggiornato']);

    $second_service = Mockery::mock(IEmbeddingService::class);
    $second_service->shouldReceive('embedDocument')
        ->once()
        ->with('Titolo italiano aggiornato')
        ->andReturn([perLocaleEmbeddingDocument([0.9, 0.9])]);
    $second_service->shouldNotReceive('embedDocument')->with('English title');

    $second_job = new GenerateEmbeddingsJob($model, 'it');
    $second_job->handle($second_service);

    $rows_after = $model->embeddings()->get();
    $it_row_after = $rows_after->firstWhere('locale', 'it');
    $en_row_after = $rows_after->firstWhere('locale', 'en');

    expect($rows_after)->toHaveCount(2)
        ->and($it_row_after->embedding)->toBe([0.9, 0.9])
        ->and($en_row_after->id)->toBe($en_row_before->id)
        ->and($en_row_after->updated_at->equalTo($en_row_before->updated_at))->toBeTrue()
        ->and($en_row_after->embedding)->toBe([0.2, 0.2]);
});

it('skips re-embedding on a full re-run when content and model are unchanged', function (): void {
    $model = makeBilingualEmbeddedModel();
    $before = $model->embeddings()->get()->keyBy('locale');

    // Full re-run (no locale) with nothing changed: the service must not be called.
    $service = Mockery::mock(IEmbeddingService::class);
    $service->shouldNotReceive('embedDocument');

    (new GenerateEmbeddingsJob($model))->handle($service);

    $after = $model->embeddings()->get()->keyBy('locale');

    expect($after)->toHaveCount(2)
        ->and($after['it']->id)->toBe($before['it']->id)
        ->and($after['en']->id)->toBe($before['en']->id)
        ->and($after['it']->embedding)->toBe([0.1, 0.1])
        ->and($after['en']->embedding)->toBe([0.2, 0.2]);
});

it('recomputes only the changed locale on a full re-run', function (): void {
    $model = makeBilingualEmbeddedModel();
    $en_before = $model->embeddings()->get()->firstWhere('locale', 'en');

    // Change only the italian translation.
    TranslatedEmbeddableTestModelTranslation::query()
        ->where('translated_embeddable_test_model_id', $model->id)
        ->where('locale', 'it')
        ->update(['title' => 'Titolo italiano aggiornato']);

    // Full re-run (no locale): only the italian embedding is regenerated.
    $service = Mockery::mock(IEmbeddingService::class);
    $service->shouldReceive('embedDocument')->once()->with('Titolo italiano aggiornato')->andReturn([perLocaleEmbeddingDocument([0.9, 0.9])]);
    $service->shouldNotReceive('embedDocument')->with('English title');

    (new GenerateEmbeddingsJob($model))->handle($service);

    $after = $model->embeddings()->get()->keyBy('locale');

    expect($after)->toHaveCount(2)
        ->and($after['it']->embedding)->toBe([0.9, 0.9])
        ->and($after['en']->id)->toBe($en_before->id)
        ->and($after['en']->embedding)->toBe([0.2, 0.2]);
});

it('synchronizes embeddings for multiple models in one call', function (): void {
    $alpha = new EmbeddableTestModel(['title' => 'Alpha']);
    $alpha->saveQuietly();
    $beta = new EmbeddableTestModel(['title' => 'Beta']);
    $beta->saveQuietly();

    $service = Mockery::mock(IEmbeddingService::class);
    $service->shouldReceive('embedDocument')->once()->with('Alpha')->andReturn([perLocaleEmbeddingDocument([0.1, 0.1])]);
    $service->shouldReceive('embedDocument')->once()->with('Beta')->andReturn([perLocaleEmbeddingDocument([0.2, 0.2])]);

    $synchronizer = new ModelEmbeddingSynchronizer($service, app(EmbeddingModelRegistry::class));
    $synchronizer->sync([$alpha, $beta]);

    expect($alpha->fresh()->embeddings()->count())->toBe(1)
        ->and($beta->fresh()->embeddings()->count())->toBe(1)
        ->and($alpha->fresh()->embeddings()->first()->embedding)->toBe([0.1, 0.1])
        ->and($beta->fresh()->embeddings()->first()->embedding)->toBe([0.2, 0.2]);
});

it('recomputes every locale when the active embedding model changed', function (): void {
    $model = makeBilingualEmbeddedModel();

    // Switch the active embedding-model profile: model_key no longer matches.
    config()->set('ai.features.embeddings.active', 'all-MiniLM-L6-v2');
    $new_key = app(EmbeddingModelRegistry::class)->active()->key;

    $service = Mockery::mock(IEmbeddingService::class);
    $service->shouldReceive('embedDocument')->once()->with('Titolo italiano')->andReturn([perLocaleEmbeddingDocument([0.3, 0.3])]);
    $service->shouldReceive('embedDocument')->once()->with('English title')->andReturn([perLocaleEmbeddingDocument([0.4, 0.4])]);

    (new GenerateEmbeddingsJob($model))->handle($service);

    $rows = $model->embeddings()->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('model_key')->unique()->all())->toBe([$new_key])
        ->and($rows->firstWhere('locale', 'it')->embedding)->toBe([0.3, 0.3])
        ->and($rows->firstWhere('locale', 'en')->embedding)->toBe([0.4, 0.4]);
});
