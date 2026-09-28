<?php

declare(strict_types=1);

namespace Modules\AI\Ai\MediaAnalysis\Contracts;

use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;
use Modules\AI\Ai\MediaAnalysis\MediaVisionResult;

/**
 * Vision analysis of an image file (M6, M20). Implementations call a vision model
 * (resolved from the profile) and return a structured {@see MediaVisionResult}.
 * Injected as a contract so the analysis job is testable with a fake and needs no
 * live model to build.
 */
interface MediaVisionAnalyzer
{
    public function analyze(string $path, string $mimeType, MediaAnalysisModelProfile $profile): MediaVisionResult;
}
