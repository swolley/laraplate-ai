<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchActivation;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchIndexes;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\AI\Services\ModelEmbeddingSynchronizer;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Modules\AI\Tests\Stubs\EmbeddingSwitchHarness as Harness;
use Modules\AI\Tests\Stubs\RecordingProfileVectorIndex;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Search\Contracts\IProfileVectorIndex;

beforeEach(function (): void {
    Event::fake([ModelPreProcessingCompleted::class]);
    $this->seed(AIDatabaseSeeder::class);
    $this->engine = Harness::boot();
    $this->records = Harness::corpus($this->engine, 'One');
});

function pgvector_hooks_fake(bool $supported): RecordingProfileVectorIndex
{
    $fake = new RecordingProfileVectorIndex($supported);
    app()->instance(IProfileVectorIndex::class, $fake);

    return $fake;
}

it('creates the index of the target in the indexes phase on pgvector only', function (bool $supported): void {
    $fake = pgvector_hooks_fake($supported);
    $target = app(EmbeddingModelRegistry::class)->get(Harness::TARGET);

    app(EmbeddingSwitchIndexes::class)->rebuild($target, 384);

    expect($fake->calls)->toBe($supported ? [['ensure', Harness::TARGET, $target->dimensions, $target->similarity]] : []);
})->with([true, false]);

it('drops the index of the deleted previous model at activation on pgvector only', function (bool $supported): void {
    $fake = pgvector_hooks_fake($supported);
    $target = app(EmbeddingModelRegistry::class)->get(Harness::TARGET);

    app(EmbeddingSwitchActivation::class)->activate($target);

    expect($fake->calls)->toBe($supported ? [['drop', Harness::ACTIVE]] : [])
        ->and(ModelEmbedding::query()->where('model_key', Harness::ACTIVE)->count())->toBe(0);
})->with([true, false]);

it('drops the index of the pruned key on pgvector only', function (bool $supported): void {
    $fake = pgvector_hooks_fake($supported);

    $this->artisan('ai:embeddings:prune', ['--model-key' => Harness::TARGET])->assertSuccessful();

    expect($fake->calls)->toBe($supported ? [['drop', Harness::TARGET]] : []);
})->with([true, false]);

it('ensures the index of the active key before writing, on pgvector only', function (bool $supported): void {
    $fake = pgvector_hooks_fake($supported);
    $active = app(EmbeddingModelRegistry::class)->active();
    $record = new EmbeddableTestModel(['title' => 'Fresh']);
    $record->saveQuietly();

    app(ModelEmbeddingSynchronizer::class)->sync([$record], announceCompletion: false);

    expect($fake->calls)->toBe($supported ? [['ensureOnce', $active->key, $active->dimensions, $active->similarity]] : []);
})->with([true, false]);
