<?php

declare(strict_types=1);

namespace Modules\AI\Ai\MediaAnalysis;

/**
 * Structured result of vision analysis on an image (M6, M7): a descriptive
 * caption (subjects + actions), recognized entities, the interpretive idea and
 * intent, and any text read from the image (OCR).
 */
final readonly class MediaVisionResult
{
    /**
     * @param  list<string>  $entities
     */
    public function __construct(
        public ?string $caption,
        public array $entities,
        public ?string $idea,
        public ?string $intent,
        public ?string $ocrText,
    ) {}

    public static function empty(): self
    {
        return new self(null, [], null, null, null);
    }
}
