<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Ai\MediaAnalysis\Contracts\MediaVisionAnalyzer;
use Modules\AI\Ai\MediaAnalysis\MediaVisionResult;
use Modules\AI\Jobs\AnalyzeMediaJob;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Tests\Stubs\MediaAnalysis\FakeMediaVisionAnalyzer;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Models\Media;
use Modules\Core\Tests\Stubs\Search\MediaOwnerStubModel;

beforeEach(function (): void {
    Storage::fake('public');
    Bus::fake([GenerateEmbeddingsJob::class]);
    Event::fake([ModelPreProcessingCompleted::class, ModelRequiresIndexing::class]);
    Schema::create('media_owner_stubs', function ($table): void {
        $table->id();
        $table->string('title')->nullable();
    });
    app()->instance(MediaVisionAnalyzer::class, new FakeMediaVisionAnalyzer(
        new MediaVisionResult('An old lighthouse', ['lighthouse'], 'solitude', 'inform', null),
    ));
});

function ownerReindexMedia(MediaOwnerStubModel $owner, string $hash): Media
{
    $media = new Media();
    $media->forceFill([
        'collection_name' => 'default',
        'name' => 'm',
        'file_name' => 'm.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'size' => 10,
        'manipulations' => [],
        'custom_properties' => ['content_hash' => $hash],
        'generated_conversions' => [],
        'responsive_images' => [],
        'order_column' => 1,
        'model_type' => $owner->getMorphClass(),
        'model_id' => $owner->getKey(),
    ]);
    $media->saveQuietly();

    return $media;
}

it('reindexes the media owner once the analysis completes', function (): void {
    $owner = MediaOwnerStubModel::query()->create(['title' => 'Article']);
    $media = ownerReindexMedia($owner, hash('sha256', 'lighthouse-a'));
    Event::fake([ModelPreProcessingCompleted::class, ModelRequiresIndexing::class]);

    app()->call([new AnalyzeMediaJob($media), 'handle']);

    Event::assertDispatched(
        ModelRequiresIndexing::class,
        static fn (ModelRequiresIndexing $event): bool => $event->model instanceof MediaOwnerStubModel && $event->model->is($owner),
    );
});

it('reindexes the owner of a duplicate that reuses an existing analysis', function (): void {
    $first = MediaOwnerStubModel::query()->create(['title' => 'First']);
    $second = MediaOwnerStubModel::query()->create(['title' => 'Second']);
    $hash = hash('sha256', 'lighthouse-b');

    app()->call([new AnalyzeMediaJob(ownerReindexMedia($first, $hash)), 'handle']);
    $duplicate = ownerReindexMedia($second, $hash);
    Event::fake([ModelPreProcessingCompleted::class, ModelRequiresIndexing::class]);

    app()->call([new AnalyzeMediaJob($duplicate), 'handle']);

    Event::assertDispatched(
        ModelRequiresIndexing::class,
        static fn (ModelRequiresIndexing $event): bool => $event->model instanceof MediaOwnerStubModel && $event->model->is($second),
    );
});
