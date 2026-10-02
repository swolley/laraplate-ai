<?php

declare(strict_types=1);

use Modules\AI\Ai\MediaAnalysis\MediaVisionResult;
use Modules\AI\Ai\MediaAnalysis\Vision\NeuronVisionAnalyzer;
use Modules\AI\Exceptions\MediaAnalysisException;

function parseVisionAnswer(string $content): MediaVisionResult
{
    $method = new ReflectionMethod(NeuronVisionAnalyzer::class, 'parse');

    /** @var MediaVisionResult */
    return $method->invoke(null, $content);
}

it('parses a JSON answer wrapped in a Markdown code fence', function (): void {
    $result = parseVisionAnswer("```json\n{\"caption\": \"A cat\", \"entities\": [\"cat\"], \"idea\": \"rest\", \"intent\": \"inform\", \"ocr_text\": \"\"}\n```");

    expect($result->caption)->toBe('A cat')
        ->and($result->entities)->toBe(['cat'])
        ->and($result->ocrText)->toBeNull();
});

it('throws when the answer is not JSON, so the job retries instead of storing an empty result', function (): void {
    parseVisionAnswer('Sorry, I cannot help with that image.');
})->throws(MediaAnalysisException::class);
