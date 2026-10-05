<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

/**
 * Advances a running embedding model switch one phase at a time. The persisted state is the only
 * memory between steps, so a worker restart loses nothing: `SwitchEmbeddingModelJob` calls
 * {@see self::advance()} and dispatches itself again only while {@see self::canAdvance()} holds.
 *
 * A phase with no handler is a resting point: the state stays where it is and nothing is
 * dispatched until a handler for it exists.
 */
final readonly class EmbeddingSwitchOrchestrator
{
    /**
     * The phases this orchestrator knows how to complete.
     */
    public const array HANDLED_PHASES = ['preflight'];

    public function __construct(
        private EmbeddingSwitchStore $store,
        private EmbeddingSwitchPreview $preview,
    ) {}

    /**
     * Completes the current phase of a running switch and stores the next state; returns the state
     * unchanged when the switch is not running or its phase has no handler.
     */
    public function advance(): EmbeddingSwitchState
    {
        $state = $this->store->get();

        if (! $this->canAdvance($state)) {
            return $state;
        }

        $next = match ($state->phase) {
            'preflight' => $this->completePreflight($state),
        };

        $this->store->put($next);

        return $next;
    }

    public function canAdvance(EmbeddingSwitchState $state): bool
    {
        return $state->status === 'running' && in_array($state->phase, self::HANDLED_PHASES, true);
    }

    /**
     * Marks the switch failed in its current phase. `active` and the suspended vector search are
     * left as they are: the serving model is intact and the indexes may be half rebuilt.
     */
    public function fail(string $error): EmbeddingSwitchState
    {
        $state = $this->store->get();

        if ($state->status !== 'running') {
            return $state;
        }

        $failed = new EmbeddingSwitchState(
            status: 'failed',
            phase: $state->phase,
            target: $state->target,
            previous: $state->previous,
            total: $state->total,
            done: $state->done,
            error: $error,
            startedAt: $state->startedAt,
        );
        $this->store->put($failed);

        return $failed;
    }

    /**
     * The command has verified the target before storing the state; what is left is to count the
     * work of the embeddings phase.
     */
    private function completePreflight(EmbeddingSwitchState $state): EmbeddingSwitchState
    {
        return new EmbeddingSwitchState(
            status: 'running',
            phase: 'embeddings',
            target: $state->target,
            previous: $state->previous,
            total: $this->preview->recordsToEmbed(),
            done: 0,
            error: null,
            startedAt: $state->startedAt,
        );
    }
}
