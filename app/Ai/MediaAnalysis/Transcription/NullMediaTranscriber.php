<?php

declare(strict_types=1);

namespace Modules\AI\Ai\MediaAnalysis\Transcription;

use Modules\AI\Ai\MediaAnalysis\Contracts\MediaTranscriber;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;

/**
 * Default transcriber: no backend configured, so it transcribes nothing. Keeps
 * the analysis job working (audio/video simply yield no transcript) until the
 * self-hosted Whisper backend is wired in Task 8 and bound over this default.
 */
final class NullMediaTranscriber implements MediaTranscriber
{
    public function transcribe(string $path, string $mimeType, MediaAnalysisModelProfile $profile): ?string
    {
        return null;
    }
}
