<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Modules\AI\Jobs\AnalyzeMediaJob;
use Modules\AI\Listeners\HandleMediaAnalysisListener;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Models\Media;
use Modules\Core\Models\MediaDraft;
use Modules\Core\Models\Setting;

function claimedMedia(): Media
{
    $media = new Media();
    $media->forceFill([
        'id' => 1,
        'collection_name' => 'default',
        'name' => 'photo',
        'file_name' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'size' => 10,
        'model_type' => 'Modules\\CMS\\Models\\Content',
        'model_id' => 1,
        'custom_properties' => [],
    ]);

    return $media;
}

beforeEach(function (): void {
    Bus::fake();
    config()->set('ai.features.media_analysis.enabled', true);
});

it('registers media_analysis, dispatches the job and marks handled for a claimed media', function (): void {
    $event = new ModelRequiresIndexing(claimedMedia());

    app(HandleMediaAnalysisListener::class)->handle($event);

    expect($event->required_pre_processing)->toContain('media_analysis')
        ->and($event->isHandled())->toBeTrue();

    Bus::assertDispatched(AnalyzeMediaJob::class);
});

it('does nothing while the media is owned by a draft', function (): void {
    $media = claimedMedia();
    $media->forceFill(['model_type' => (new MediaDraft())->getMorphClass()]);

    $event = new ModelRequiresIndexing($media);
    app(HandleMediaAnalysisListener::class)->handle($event);

    expect($event->isHandled())->toBeFalse();
    Bus::assertNotDispatched(AnalyzeMediaJob::class);
});

it('does nothing when the master switch is off (fallback indexes deterministic)', function (): void {
    config()->set('ai.features.media_analysis.enabled', false);

    $event = new ModelRequiresIndexing(claimedMedia());
    app(HandleMediaAnalysisListener::class)->handle($event);

    expect($event->isHandled())->toBeFalse();
    Bus::assertNotDispatched(AnalyzeMediaJob::class);
});

it('ignores non-media models', function (): void {
    $event = new ModelRequiresIndexing(new Setting());
    app(HandleMediaAnalysisListener::class)->handle($event);

    expect($event->isHandled())->toBeFalse();
    Bus::assertNotDispatched(AnalyzeMediaJob::class);
});
