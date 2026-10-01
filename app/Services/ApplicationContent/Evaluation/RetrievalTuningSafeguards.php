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
 * - `noiseMargin`: a gain no bigger than this is noise, not a result. The winner must beat the
 *   committed profile by more than it, or there is no winner; a class override must beat the
 *   overall winner by more than it too. `null` sizes it on the sample, at one case of it (what a
 *   single flipped case moves the metric by) with a floor of {@see self::NOISE_FLOOR}; zero turns it off.
 *
 * The defaults reproduce the unguarded search; {@see self::recommended()} is what the command uses.
 */
final readonly class RetrievalTuningSafeguards
{
    public const float NOISE_FLOOR = 0.01;

    public function __construct(
        public float $holdoutFraction = 0.0,
        public int $minClassCases = 1,
        public float $classMargin = 0.0,
        public ?float $noiseMargin = 0.0,
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

        if ($noiseMargin !== null && ($noiseMargin < 0.0 || $noiseMargin > 1.0)) {
            throw new InvalidArgumentException('The noise margin must be between 0 and 1.');
        }
    }

    public static function recommended(): self
    {
        return new self(holdoutFraction: 0.3, minClassCases: 8, classMargin: 0.01, noiseMargin: null);
    }

    /**
     * The margin that applies to a sample of this many cases: a fixed one as is, the automatic one
     * as one case of the sample (never below the floor), zero when the check is off.
     */
    public function tolerance(int $cases): float
    {
        if ($this->noiseMargin !== null) {
            return $this->noiseMargin;
        }

        return round(max(self::NOISE_FLOOR, 1 / max(1, $cases)), 4);
    }

    /**
     * @return array{holdout_fraction: float, min_class_cases: int, class_margin: float, noise_margin: float|'auto'}
     */
    public function toArray(): array
    {
        return [
            'holdout_fraction' => $this->holdoutFraction,
            'min_class_cases' => $this->minClassCases,
            'class_margin' => $this->classMargin,
            'noise_margin' => $this->noiseMargin ?? 'auto',
        ];
    }
}
