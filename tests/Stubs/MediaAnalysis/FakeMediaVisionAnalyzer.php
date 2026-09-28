<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\MediaAnalysis;

use Modules\AI\Ai\MediaAnalysis\Contracts\MediaVisionAnalyzer;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;
use Modules\AI\Ai\MediaAnalysis\MediaVisionResult;
use RuntimeException;

/**
 * Returns a canned vision result. When constructed without one it throws on use,
 * to prove the analyzer is not called on the lookup-before-work reuse path.
 */
final class FakeMediaVisionAnalyzer implements MediaVisionAnalyzer
{
    public function __construct(private readonly ?MediaVisionResult $result = null) {}

    public function analyze(string $path, string $mimeType, MediaAnalysisModelProfile $profile): MediaVisionResult
    {
        if (! $this->result instanceof MediaVisionResult) {
            throw new RuntimeException('Vision analyzer should not have been called.');
        }

        return $this->result;
    }
}
