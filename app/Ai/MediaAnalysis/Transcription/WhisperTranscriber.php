<?php

declare(strict_types=1);

namespace Modules\AI\Ai\MediaAnalysis\Transcription;

use function ai_config_string;

use Illuminate\Support\Facades\Http;
use Modules\AI\Ai\MediaAnalysis\Contracts\MediaTranscriber;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;
use Throwable;

/**
 * Transcribes audio/video by POSTing the file to the self-hosted Whisper service
 * (Task 8), mirroring the self-hosted embedding service pattern: base URL +
 * optional Bearer key from config. When no URL is configured, or on any error, it
 * returns null so the media still indexes without a transcript (M12). Returns the
 * transcript in the spoken/source language (M17).
 */
final class WhisperTranscriber implements MediaTranscriber
{
    public function transcribe(string $path, string $mimeType, MediaAnalysisModelProfile $profile): ?string
    {
        $url = ai_config_string('ai.providers.whisper.url', '');

        if ($url === '' || ! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $timeout = config('ai.providers.whisper.timeout', 120);
        $timeout = is_numeric($timeout) ? (int) $timeout : 120;

        try {
            $request = Http::timeout($timeout)
                ->attach('file', $contents, basename($path));

            $apiKey = ai_config_string('ai.providers.whisper.api_key', '');

            if ($apiKey !== '') {
                $request = $request->withToken($apiKey);
            }

            $response = $request->post(mb_rtrim($url, '/') . '/transcribe');

            if (! $response->successful()) {
                return null;
            }

            $text = $response->json('text');
        } catch (Throwable) {
            return null;
        }

        return is_string($text) && mb_trim($text) !== '' ? mb_trim($text) : null;
    }
}
