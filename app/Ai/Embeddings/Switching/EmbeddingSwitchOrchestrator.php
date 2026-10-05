<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Throwable;

/**
 * Advances a running embedding model switch one phase at a time, in order: `preflight`,
 * `embeddings`, `indexes`, `verify`, `activate`. The persisted state is the only memory between
 * steps, so a worker restart loses nothing: `SwitchEmbeddingModelJob` calls {@see self::advance()}
 * and dispatches itself again while {@see self::canAdvance()} holds. Every step writes the state,
 * which is also the heartbeat {@see EmbeddingSwitchState::isInterrupted()} reads.
 *
 * Only `embeddings` waits on queued work: it dispatches `GenerateEmbeddingsJob` with the target for
 * every record still missing a row of it, then stays in the phase until every record has one. When
 * the queue is empty and records are still missing, it dispatches them again, up to
 * {@see self::MAX_EMBEDDING_ROUNDS} times, then fails.
 *
 * A check that does not hold fails the switch in its phase ({@see EmbeddingSwitchPhaseFailed}).
 * Any other error propagates, so the job retries it and records it once its tries are spent.
 * `active`, the suspension of vector search and the previous model's rows are left as they are.
 */
final readonly class EmbeddingSwitchOrchestrator
{
    /**
     * The phases this orchestrator knows how to complete.
     */
    public const array HANDLED_PHASES = ['preflight', 'embeddings', 'indexes', 'verify', 'activate'];

    public const int MAX_EMBEDDING_ROUNDS = 3;

    public function __construct(
        private EmbeddingSwitchStore $store,
        private EmbeddingSwitchPreview $preview,
        private EmbeddingModelRegistry $registry,
        private EmbeddingSwitchCorpus $corpus,
        private EmbeddingSwitchIndexes $indexes,
        private EmbeddingSwitchVerifier $verifier,
        private EmbeddingSwitchActivation $activation,
    ) {}

    /**
     * Runs the current phase of a running switch and stores the next state; returns the state
     * unchanged when the switch is not running.
     */
    public function advance(): EmbeddingSwitchState
    {
        $state = $this->store->get();

        if (! $this->canAdvance($state)) {
            return $state;
        }

        try {
            if ($state->phase === 'activate') {
                return $this->activation->activate($this->target($state));
            }

            $next = match ($state->phase) {
                'preflight' => $this->completePreflight($state),
                'embeddings' => $this->embed($state),
                'indexes' => $this->rebuildIndexes($state),
                'verify' => $this->verify($state),
            };
        } catch (EmbeddingSwitchPhaseFailed $failure) {
            return $this->fail($failure->getMessage());
        }

        $next = $next->with(updatedAt: now()->toIso8601String());
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

        $failed = $state->with(status: 'failed', error: $error, updatedAt: now()->toIso8601String());
        $this->store->put($failed);

        return $failed;
    }

    /**
     * The command has verified the target before storing the state; what is left is to count the
     * work of the embeddings phase.
     */
    private function completePreflight(EmbeddingSwitchState $state): EmbeddingSwitchState
    {
        return $state->with(
            phase: 'embeddings',
            total: $this->preview->recordsToEmbed(),
            done: 0,
            rounds: 0,
            error: null,
        );
    }

    private function embed(EmbeddingSwitchState $state): EmbeddingSwitchState
    {
        $target = $this->target($state)->key;
        $progress = $this->corpus->progress($target);
        $counted = $state->with(total: $progress['total'], done: $progress['done']);

        if ($progress['pending'] === []) {
            return $counted->with(phase: 'indexes', rounds: 0);
        }

        if ($state->rounds > 0 && Queue::size(GenerateEmbeddingsJob::QUEUE) > 0) {
            return $counted;
        }

        if ($state->rounds >= self::MAX_EMBEDDING_ROUNDS) {
            throw new EmbeddingSwitchPhaseFailed(count($progress['pending']) . " record(s) still have no embedding of \"{$target}\" after " . self::MAX_EMBEDDING_ROUNDS . ' rounds: ' . EmbeddingSwitchCorpus::describe($progress['pending']));
        }

        $this->dispatchEmbeddings($progress['pending'], $target);

        return $counted->with(rounds: $state->rounds + 1);
    }

    /**
     * Dispatches a full per-locale embedding of each record with the target. A dispatch that throws
     * (a synchronous job that failed, a queue that refused it) is logged: the record is still
     * missing at the next pass and is dispatched again in the next round.
     *
     * @param  list<Model>  $records
     */
    private function dispatchEmbeddings(array $records, string $target): void
    {
        foreach ($records as $record) {
            try {
                app(Dispatcher::class)->dispatch(new GenerateEmbeddingsJob($record->withoutRelations(), null, $target));
            } catch (Throwable $exception) {
                Log::warning('Embedding model switch: could not embed a record with the target', [
                    'model' => $record::class,
                    'model_id' => $record->getKey(),
                    'target' => $target,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function rebuildIndexes(EmbeddingSwitchState $state): EmbeddingSwitchState
    {
        $this->indexes->rebuild($this->target($state), $this->activeDimensions());

        return $state->with(phase: 'verify', rounds: 0);
    }

    /**
     * Rewrites every document with the target's vectors first, then checks. A record edited after
     * the `indexes` phase is embedded with the target (the switch runs), but the normal pipeline
     * writes its document with the rows of the serving model, which is still the previous one; the
     * refresh replaces those documents, also when a switch left failed in `verify` is resumed days
     * later. A record edited between this refresh and the activation (the checks and the settings
     * write, seconds) keeps such a document until it is saved again.
     */
    private function verify(EmbeddingSwitchState $state): EmbeddingSwitchState
    {
        $target = $this->target($state);

        $this->indexes->refresh($target);
        $this->verifier->verify($target);

        return $state->with(phase: 'activate');
    }

    /**
     * @throws EmbeddingSwitchPhaseFailed when the stored target is no longer a profile
     */
    private function target(EmbeddingSwitchState $state): EmbeddingModelProfile
    {
        try {
            return $this->registry->get((string) $state->target);
        } catch (InvalidArgumentException $exception) {
            throw new EmbeddingSwitchPhaseFailed($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * The dimensions of the serving model: what an index whose engine cannot report its mapping is
     * assumed to hold.
     */
    private function activeDimensions(): ?int
    {
        try {
            return $this->registry->get($this->registry->activeKey())->dimensions;
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
