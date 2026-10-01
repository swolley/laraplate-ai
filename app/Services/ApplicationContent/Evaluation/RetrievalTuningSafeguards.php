<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Evaluation;

use InvalidArgumentException;

/**
 * What stops the grid search from picking noise.
 *
 * The tuner scores every candidate on the same cases it then chooses from, so a small or lopsided
 * dataset can crown a winner that only memorised it. Three guards narrow that:
 *
 * - `holdoutFraction`: that share of the cases, picked deterministically from the case id, is kept
 *   out of the selection. The winner must not lose to the committed profile on them, or there is no
 *   winner. Zero turns the validation off.
 * - `minClassCases`: a query class gets its own override only with at least this many selection cases.
 * - `classMargin`: and only when it beats the overall winner on that class by more than this.
 *
 * The defaults reproduce the unguarded search; {@see self::recommended()} is what the command uses.
 */
final readonly class RetrievalTuningSafeguards
{
    public function __construct(
        public float $holdoutFraction = 0.0,
        public int $minClassCases = 1,
        public float $classMargin = 0.0,
    ) {
        if ($holdoutFraction < 0.0 || $holdoutFraction > 0.5) {
            throw new InvalidArgumentException('The held-out fraction must be between 0 and 0.5.');
        }

        if ($minClassCases < 1) {
            throw new InvalidArgumentException('A class override needs at least one case.');
        }

        if ($classMargin < 0.0 || $classMargin > 1.0) {
            throw new InvalidArgumentException('The class margin must be between 0 and 1.');
        }
    }

    public static function recommended(): self
    {
        return new self(holdoutFraction: 0.3, minClassCases: 8, classMargin: 0.01);
    }

    /**
     * @return array{holdout_fraction: float, min_class_cases: int, class_margin: float}
     */
    public function toArray(): array
    {
        return [
            'holdout_fraction' => $this->holdoutFraction,
            'min_class_cases' => $this->minClassCases,
            'class_margin' => $this->classMargin,
        ];
    }
}
