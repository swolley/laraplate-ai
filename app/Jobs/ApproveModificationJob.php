<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use function ai_config_bool;
use function ai_config_float;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\AI\Data\ModerationResult;
use Modules\AI\Enums\ModerationApprovalMode;
use Modules\AI\Enums\ModerationVerdict;
use Modules\AI\Services\ModerationService;
use Modules\AI\Services\ModerationSystemUser;
use Modules\Core\Events\ModificationPreProcessingCompleted;
use Modules\Core\Models\Modification;
use Modules\Core\Models\User;
use Modules\Core\Services\ModerationAdapterRegistry;
use Modules\Core\Services\ModificationVoteService;
use Throwable;

final class ApproveModificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Modification $modification,
    ) {}

    public function handle(
        ModerationService $service,
        ModerationAdapterRegistry $registry,
        ModerationSystemUser $system_users,
    ): void {
        $modification = $this->modification->fresh();

        if (! $modification instanceof Modification || ! $modification->active) {
            return;
        }

        $system_user = $system_users->resolve();

        if (! $system_user instanceof User) {
            return;
        }

        try {
            if (! $registry->supports($modification)) {
                return;
            }

            $result = $this->analyze($service, $registry, $modification);

            if (! ai_config_bool('ai.features.moderation.votes', true)) {
                return;
            }

            if (! $result instanceof ModerationResult) {
                $this->applyUncertainFallback($modification, $system_user);

                return;
            }

            if (ModerationApprovalMode::fromConfig() === ModerationApprovalMode::Dual) {
                $this->handleDualMode($modification, $system_user, $result);

                return;
            }

            $this->handleThresholdMode($modification, $system_user, $result);
        } finally {
            event(new ModificationPreProcessingCompleted($modification, 'ai_approval'));
        }
    }

    /**
     * The moderation verdict, or null when the analysis itself failed. Only the analysis falls
     * back to human review: a failure while voting propagates, so the job is retried instead of
     * replacing a vote already cast with the fallback one.
     */
    private function analyze(
        ModerationService $service,
        ModerationAdapterRegistry $registry,
        Modification $modification,
    ): ?ModerationResult {
        try {
            return $service->analyze($registry->build($modification));
        } catch (Throwable $exception) {
            Log::warning('AI moderation analysis failed; falling back to human review', [
                'modification_id' => $modification->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function handleThresholdMode(
        Modification $modification,
        User $system_user,
        ModerationResult $result,
    ): void {
        $approve_threshold = ai_config_float('ai.features.moderation.threshold.approve', 0.85);
        $reject_threshold = ai_config_float('ai.features.moderation.threshold.reject', 0.85);

        if ($result->safeToAutoApprove && $result->confidence >= $approve_threshold) {
            $this->castVote($system_user, $modification, true, 1, 1, $result->reason, $this->buildVoteMeta($result, 'auto_approved'));

            return;
        }

        if ($result->verdict === ModerationVerdict::Reject && $result->confidence >= $reject_threshold) {
            $this->castVote($system_user, $modification, false, 1, 1, $result->reason, $this->buildVoteMeta($result, 'auto_rejected'));

            return;
        }

        $this->applyUncertainFallback($modification, $system_user, $result);
    }

    private function handleDualMode(
        Modification $modification,
        User $system_user,
        ModerationResult $result,
    ): void {
        $approves = $result->verdict === ModerationVerdict::Approve;

        $this->castVote($system_user, $modification, $approves, 2, 2, $result->reason, $this->buildVoteMeta($result, 'requires_human_review', [
            'requires_human_approval' => true,
            'preliminary_disapproval' => ! $approves,
        ]));
    }

    private function ensureModifiableRelation(Modification $modification): void
    {
        if ($modification->modifiable !== null) {
            return;
        }

        $modifiable_class = $modification->modifiable_type;

        if (is_string($modifiable_class) && class_exists($modifiable_class)) {
            $modification->setRelation('modifiable', new $modifiable_class());
        }
    }

    private function applyUncertainFallback(
        Modification $modification,
        User $system_user,
        ?ModerationResult $result = null,
    ): void {
        $reason = $result !== null
            ? 'AI preliminary reject (confidence ' . $result->confidence . '): ' . $result->reason
            : 'AI moderation failed; human review required.';

        $this->castVote($system_user, $modification, false, 1, 2, $reason, $this->buildVoteMeta($result, 'requires_human_review', [
            'requires_human_approval' => true,
            'preliminary_disapproval' => true,
        ]));
    }

    /**
     * Cast the AI vote together with the quorum it implies, in one Core transaction, so a
     * quorum the existing votes already reach is applied rather than left pending.
     *
     * @param  array<string, mixed>  $meta
     */
    private function castVote(
        User $system_user,
        Modification $modification,
        bool $approval,
        int $approvers_required,
        int $disapprovers_required,
        string $reason,
        array $meta,
    ): void {
        $this->ensureModifiableRelation($modification);

        $this->asSystemUser($system_user, static function () use ($system_user, $modification, $approval, $approvers_required, $disapprovers_required, $reason, $meta): void {
            resolve(ModificationVoteService::class)->castWithQuorum(
                $system_user,
                $modification,
                $approval,
                $approvers_required,
                $disapprovers_required,
                $reason,
                $meta,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function buildVoteMeta(?ModerationResult $result, string $status, array $extra = []): array
    {
        return array_merge([
            'source' => 'ai',
            'status' => $status,
            'verdict' => $result?->verdict->value,
            'confidence' => $result?->confidence,
            'categories' => $result !== null ? $result->categories : [],
            'reason' => $result?->reason,
            'analyzed_at' => now()->toIso8601String(),
        ], $extra);
    }

    private function asSystemUser(User $system_user, callable $callback): void
    {
        Auth::login($system_user);

        try {
            $callback();
        } finally {
            Auth::logout();
        }
    }
}
