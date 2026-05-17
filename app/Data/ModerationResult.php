<?php

declare(strict_types=1);

namespace Modules\AI\Data;

use Modules\AI\Enums\ModerationVerdict;

final readonly class ModerationResult
{
    /**
     * @param  list<string>  $categories
     */
    public function __construct(
        public ModerationVerdict $verdict,
        public float $confidence,
        public array $categories,
        public string $reason,
        public bool $safeToAutoApprove,
    ) {}
}
