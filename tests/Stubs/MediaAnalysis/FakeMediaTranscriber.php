<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\MediaAnalysis;

use Modules\AI\Ai\MediaAnalysis\Contracts\MediaTranscriber;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;

/**
 * Returns a canned transcript, for exercising the audio/video branch without a
 * real transcription backend.
 */
final class FakeMediaTranscriber implements MediaTranscriber
{
    public function __construct(private readonly ?string $transcript) {}

    public function transcribe(string $path, string $mimeType, MediaAnalysisModelProfile $profile): ?string
    {
        return $this->transcript;
    }
}
