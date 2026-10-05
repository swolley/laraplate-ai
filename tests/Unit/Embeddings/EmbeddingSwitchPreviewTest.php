<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionProbe;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchPreview;
use Modules\AI\Contracts\IEmbeddableModels;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Modules\AI\Tests\Stubs\TranslatedEmbeddableTestModel;
use Modules\AI\Tests\Stubs\TranslatedEmbeddableTestModelTranslation;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('ai.providers.sentence_transformers.url', 'http://localhost:8000');
    config()->set('ai.features.embeddings.models', [
        'sentence_transformers:intfloat/multilingual-e5-small' => ['dimensions' => 384],
        'sentence_transformers:all-MiniLM-L6-v2' => ['dimensions' => 384],
        'sentence_transformers:BAAI/bge-m3' => ['dimensions' => 1024],
    ]);
    config()->set('ai.features.embeddings.active', 'sentence_transformers:intfloat/multilingual-e5-small');

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

/**
 * A preview whose probe answers with a vector of $dimensions components, or throws when null.
 *
 * @param  list<class-string>  $models
 */
function switch_preview(array $models = [], ?int $dimensions = 384): EmbeddingSwitchPreview
{
    $provider = Mockery::mock(EmbeddingsProviderInterface::class);

    if ($dimensions === null) {
        $provider->shouldReceive('embedText')->andThrow(new RuntimeException('connection refused'));
    } else {
        $provider->shouldReceive('embedText')->andReturn(array_fill(0, $dimensions, 0.1));
    }

    $embeddable = Mockery::mock(IEmbeddableModels::class);
    $embeddable->shouldReceive('all')->andReturn($models);

    $registry = app(EmbeddingModelRegistry::class);

    return new EmbeddingSwitchPreview(
        $registry,
        $embeddable,
        new EmbeddingDimensionProbe($registry, static fn (): EmbeddingsProviderInterface => $provider),
    );
}

it('reports equal dimensions as a re-embed only', function (): void {
    $registry = app(EmbeddingModelRegistry::class);

    $preview = switch_preview()->for($registry->get('sentence_transformers:all-MiniLM-L6-v2'));

    expect($preview->currentModel)->toBe('sentence_transformers:intfloat/multilingual-e5-small')
        ->and($preview->targetModel)->toBe('sentence_transformers:all-MiniLM-L6-v2')
        ->and($preview->currentDimensions)->toBe(384)
        ->and($preview->targetDimensions)->toBe(384)
        ->and($preview->dimensionsDiffer)->toBeFalse();
});

it('reports the declared target dimensions when they differ', function (): void {
    $registry = app(EmbeddingModelRegistry::class);

    $preview = switch_preview(dimensions: 1024)->for($registry->get('sentence_transformers:BAAI/bge-m3'));

    expect($preview->targetDimensions)->toBe(1024)
        ->and($preview->dimensionsDiffer)->toBeTrue();
});

it('counts the searchable records times their translations', function (): void {
    $first = new TranslatedEmbeddableTestModel();
    $first->saveQuietly();
    $second = new TranslatedEmbeddableTestModel();
    $second->saveQuietly();

    foreach ([[$first, 'en'], [$first, 'it'], [$second, 'en']] as [$record, $locale]) {
        TranslatedEmbeddableTestModelTranslation::query()->create([
            'translated_embeddable_test_model_id' => $record->getKey(),
            'locale' => $locale,
            'title' => 'A title',
        ]);
    }

    (new EmbeddableTestModel(['title' => 'One']))->saveQuietly();
    (new EmbeddableTestModel(['title' => 'Two']))->saveQuietly();

    Http::fake(['*/embed' => Http::response(['model' => 'all-MiniLM-L6-v2', 'embeddings' => [array_fill(0, 384, 0.1)]])]);
    $registry = app(EmbeddingModelRegistry::class);
    $preview = switch_preview([TranslatedEmbeddableTestModel::class, EmbeddableTestModel::class])
        ->for($registry->get('sentence_transformers:all-MiniLM-L6-v2'));

    expect($preview->recordsToEmbed)->toBe(5)
        ->and($preview->estimatedSeconds)->toBeInt();
});

it('gives no estimate, after a short timeout, when the service cannot be measured', function (): void {
    $timeouts = [];
    Http::fake(['*/embed' => static function ($request, array $options) use (&$timeouts): never {
        $timeouts[] = $options['timeout'] ?? null;

        throw new ConnectionException('timed out');
    }]);
    $registry = app(EmbeddingModelRegistry::class);

    $preview = switch_preview(dimensions: null)->for($registry->get('sentence_transformers:all-MiniLM-L6-v2'));

    expect($preview->estimatedSeconds)->toBeNull()
        ->and($timeouts)->toBe([EmbeddingSwitchPreview::LATENCY_TIMEOUT_SECONDS])
        ->and(config('ai.providers.sentence_transformers.timeout'))->not->toBe(EmbeddingSwitchPreview::LATENCY_TIMEOUT_SECONDS);
});

it('measures again once a failed measurement has expired from the cache', function (): void {
    $reachable = false;
    Http::fake(['*/embed' => static function () use (&$reachable) {
        if (! $reachable) {
            throw new ConnectionException('timed out');
        }

        return Http::response(['model' => 'all-MiniLM-L6-v2', 'embeddings' => [array_fill(0, 384, 0.1)]]);
    }]);
    $target = app(EmbeddingModelRegistry::class)->get('sentence_transformers:all-MiniLM-L6-v2');
    $preview = switch_preview();

    expect($preview->for($target)->estimatedSeconds)->toBeNull();

    $reachable = true;
    expect($preview->for($target)->estimatedSeconds)->toBeNull();

    $this->travel(31)->seconds();
    expect($preview->for($target)->estimatedSeconds)->toBeInt();
});
