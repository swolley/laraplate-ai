<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Writes;

/**
 * How many writes the assistant may propose in one turn, whatever the tool.
 *
 * `MAX_RUNS` limits how often the model may call one tool; this limits the damage of a model that
 * spreads an action over many small proposals, each of which looks reasonable. One instance serves one
 * request (scoped binding) and `respond()` starts every turn from zero.
 */
final class AssistantWriteBudget
{
    public const int MAX_WRITES_PER_TURN = 5;

    private int $used = 0;

    public function startTurn(): void
    {
        $this->used = 0;
    }

    /**
     * Takes one proposal from the budget. False when the turn has used it all, and nothing is taken.
     */
    public function take(): bool
    {
        if ($this->used >= self::MAX_WRITES_PER_TURN) {
            return false;
        }

        $this->used++;

        return true;
    }

    public function used(): int
    {
        return $this->used;
    }
}
