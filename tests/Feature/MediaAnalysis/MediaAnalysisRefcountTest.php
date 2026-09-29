<?php

declare(strict_types=1);

use Modules\AI\Enums\MediaAnalysisStatus;
use Modules\AI\Models\MediaAnalysis;
use Modules\Core\Models\Media;

function refcountMedia(string $hash): Media
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
        'model_type' => 'Modules\\Nowhere\\Owner',
        'model_id' => 1,
    ]);
    $media->saveQuietly();

    return $media;
}

function refcountAnalysis(string $hash): MediaAnalysis
{
    return MediaAnalysis::query()->create([
        'content_hash' => $hash,
        'analysis_status' => MediaAnalysisStatus::Completed,
    ]);
}

it('keeps a shared analysis while another media still references its file', function (): void {
    refcountAnalysis('shared');
    $first = refcountMedia('shared');
    refcountMedia('shared');

    $first->forceDelete();

    expect(MediaAnalysis::query()->where('content_hash', 'shared')->exists())->toBeTrue();
});

it('keeps the analysis when the media is only soft-deleted', function (): void {
    refcountAnalysis('trashed');
    refcountMedia('trashed')->delete();

    expect(MediaAnalysis::query()->where('content_hash', 'trashed')->exists())->toBeTrue();
});

it('deletes the analysis with the last media for its file', function (): void {
    refcountAnalysis('last');
    $first = refcountMedia('last');
    $second = refcountMedia('last');

    $first->forceDelete();
    $second->forceDelete();

    expect(MediaAnalysis::query()->where('content_hash', 'last')->exists())->toBeFalse();
});
