<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Modules\AI\Models\MediaAnalysis;
use Modules\AI\Search\MediaAnalysisSearchContributor;
use Modules\Core\Models\Media;

function mediaWithHash(string $hash): Media
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
        'model_type' => 'Modules\\CMS\\Models\\Content',
        'model_id' => 1,
    ]);

    return $media;
}

beforeEach(function (): void {
    Storage::fake('public');
});

it('contributes analysis fields and embeddable text for a media by content hash', function (): void {
    $hash = hash('sha256', 'file-x');
    MediaAnalysis::factory()->create([
        'content_hash' => $hash,
        'idea' => 'biodiversity',
        'intent' => 'document',
        'entities' => ['lion', 'elephant'],
        'transcript' => 'the narrator speaks',
    ]);

    $contributor = new MediaAnalysisSearchContributor();
    $media = mediaWithHash($hash);

    expect($contributor->searchableFields($media))
        ->toMatchArray(['idea' => 'biodiversity', 'intent' => 'document', 'entities' => ['lion', 'elephant']]);

    expect($contributor->embeddableText($media))
        ->toContain('biodiversity')
        ->toContain('the narrator speaks')
        ->toContain('lion');
});

it('contributes nothing when the media has no analysis row', function (): void {
    $contributor = new MediaAnalysisSearchContributor();
    $media = mediaWithHash(hash('sha256', 'no-analysis'));

    expect($contributor->searchableFields($media))->toBe([])
        ->and($contributor->embeddableText($media))->toBeNull();
});
