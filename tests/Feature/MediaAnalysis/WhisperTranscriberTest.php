<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;
use Modules\AI\Ai\MediaAnalysis\Transcription\WhisperTranscriber;

function transcriptionProfile(): MediaAnalysisModelProfile
{
    return new MediaAnalysisModelProfile('transcription', 'whisper', 'whisper', '');
}

function audioFixture(): string
{
    $path = tempnam(sys_get_temp_dir(), 'wh') . '.mp3';
    file_put_contents($path, 'fake audio bytes');

    return $path;
}

it('posts the file to the configured Whisper service and returns the transcript', function (): void {
    config()->set('ai.providers.whisper.url', 'http://whisper.test');
    config()->set('ai.providers.whisper.api_key', 'secret');
    Http::fake(['whisper.test/*' => Http::response(['text' => 'hello world', 'language' => 'en'])]);

    $path = audioFixture();
    $result = (new WhisperTranscriber())->transcribe($path, 'audio/mpeg', transcriptionProfile());

    expect($result)->toBe('hello world');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/transcribe')
        && $request->hasHeader('Authorization', 'Bearer secret'));

    @unlink($path);
});

it('is a no-op when no Whisper URL is configured', function (): void {
    config()->set('ai.providers.whisper.url', null);
    Http::fake();

    $path = audioFixture();
    $result = (new WhisperTranscriber())->transcribe($path, 'audio/mpeg', transcriptionProfile());

    expect($result)->toBeNull();
    Http::assertNothingSent();

    @unlink($path);
});

it('returns null when the service responds with an error', function (): void {
    config()->set('ai.providers.whisper.url', 'http://whisper.test');
    Http::fake(['whisper.test/*' => Http::response('boom', 500)]);

    $path = audioFixture();
    $result = (new WhisperTranscriber())->transcribe($path, 'audio/mpeg', transcriptionProfile());

    expect($result)->toBeNull();

    @unlink($path);
});
