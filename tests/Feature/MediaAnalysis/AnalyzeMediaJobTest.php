<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Ai\MediaAnalysis\Contracts\MediaTranscriber;
use Modules\AI\Ai\MediaAnalysis\Contracts\MediaVisionAnalyzer;
use Modules\AI\Ai\MediaAnalysis\MediaVisionResult;
use Modules\AI\Enums\MediaAnalysisStatus;
use Modules\AI\Jobs\AnalyzeMediaJob;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Models\MediaAnalysis;
use Modules\AI\Tests\Stubs\MediaAnalysis\FakeMediaTranscriber;
use Modules\AI\Tests\Stubs\MediaAnalysis\FakeMediaVisionAnalyzer;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Models\Media;

function persistMedia(string $mime, string $hash): Media
{
    $media = new Media();
    $media->forceFill([
        'collection_name' => 'default',
        'name' => 'm',
        'file_name' => 'm.bin',
        'mime_type' => $mime,
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
    $media->save();

    return $media;
}

function runAnalyze(Media $media): void
{
    app()->call([new AnalyzeMediaJob($media), 'handle']);
}

beforeEach(function (): void {
    Storage::fake('public');
    Bus::fake([GenerateEmbeddingsJob::class]);
    Event::fake([ModelPreProcessingCompleted::class]);
});

it('writes the analysis row, fills empty Core fields and chains embeddings for an image', function (): void {
    app()->instance(MediaVisionAnalyzer::class, new FakeMediaVisionAnalyzer(
        new MediaVisionResult('A man smiling', ['man'], 'joy', 'inform', null),
    ));

    $media = persistMedia('image/jpeg', hash('sha256', 'file-a'));

    runAnalyze($media);

    $analysis = MediaAnalysis::query()->firstWhere('content_hash', hash('sha256', 'file-a'));
    expect($analysis)->not->toBeNull()
        ->and($analysis->analysis_status)->toBe(MediaAnalysisStatus::Completed)
        ->and($analysis->idea)->toBe('joy')
        ->and($analysis->intent)->toBe('inform')
        ->and($analysis->entities)->toBe(['man'])
        ->and($analysis->analysis_model_version)->toBe('anthropic:claude-sonnet-5');

    $custom = $media->fresh()->custom_properties;
    expect($custom['description'])->toBe('A man smiling')
        ->and($custom['keywords'])->toBe(['man'])
        ->and($custom['_provenance']['description'])->toBe('llm');

    Bus::assertDispatched(GenerateEmbeddingsJob::class);
    Event::assertDispatched(ModelPreProcessingCompleted::class);
});

it('reuses a fresh analysis for the same hash without calling the analyzer (M15)', function (): void {
    $hash = hash('sha256', 'shared');
    MediaAnalysis::factory()->create([
        'content_hash' => $hash,
        'analysis_model_version' => 'anthropic:claude-sonnet-5',
    ]);

    // No result => the fake throws if analyze() is called.
    app()->instance(MediaVisionAnalyzer::class, new FakeMediaVisionAnalyzer());

    $media = persistMedia('image/jpeg', $hash);

    runAnalyze($media);

    expect(MediaAnalysis::query()->where('content_hash', $hash)->count())->toBe(1);
    Bus::assertDispatched(GenerateEmbeddingsJob::class);
    Event::assertDispatched(ModelPreProcessingCompleted::class);
});

it('transcribes audio through the transcriber contract', function (): void {
    app()->instance(MediaTranscriber::class, new FakeMediaTranscriber('hello world'));

    $media = persistMedia('audio/mpeg', hash('sha256', 'audio-a'));

    runAnalyze($media);

    $analysis = MediaAnalysis::query()->firstWhere('content_hash', hash('sha256', 'audio-a'));
    expect($analysis->transcript)->toBe('hello world')
        ->and($analysis->analysis_status)->toBe(MediaAnalysisStatus::Completed);
});
