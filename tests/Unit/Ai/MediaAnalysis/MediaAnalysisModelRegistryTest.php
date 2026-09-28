<?php

declare(strict_types=1);

use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelRegistry;

it('resolves the active vision profile from config', function (): void {
    $profile = (new MediaAnalysisModelRegistry())->active('vision');

    expect($profile)->toBeInstanceOf(MediaAnalysisModelProfile::class)
        ->and($profile->capability)->toBe('vision')
        ->and($profile->key)->toBe('claude-sonnet-5')
        ->and($profile->provider)->toBe('anthropic')
        ->and($profile->serviceModel)->toBe('claude-sonnet-5');
});

it('resolves the active transcription profile from config', function (): void {
    $profile = (new MediaAnalysisModelRegistry())->active('transcription');

    expect($profile->capability)->toBe('transcription')
        ->and($profile->key)->toBe('whisper-local')
        ->and($profile->provider)->toBe('whisper_local');
});

it('follows the configured active key', function (): void {
    config()->set('ai.features.media_analysis.capabilities.vision.active', 'claude-haiku-4-5');

    expect((new MediaAnalysisModelRegistry())->active('vision')->key)->toBe('claude-haiku-4-5');
});

it('throws on an unknown capability', function (): void {
    (new MediaAnalysisModelRegistry())->get('nope', 'anything');
})->throws(InvalidArgumentException::class);

it('throws on an unknown model key within a capability', function (): void {
    (new MediaAnalysisModelRegistry())->get('vision', 'nope');
})->throws(InvalidArgumentException::class);
