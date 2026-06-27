<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Modules\AI\Data\ModerationResult;
use Modules\AI\Enums\ModerationApprovalMode;
use Modules\AI\Enums\ModerationVerdict;
use Modules\AI\Services\ModerationService;
use Modules\Core\Events\ModificationPreProcessingCompleted;
use Modules\Core\Models\Approval;
use Modules\Core\Models\Disapproval;
use Modules\Core\Models\Modification;
use Modules\Core\Models\User;
use Modules\Core\Services\ModerationAdapterRegistry;
use Throwable;

use function ai_config_bool;
use function ai_config_float;
use function ai_config_int;

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
    ): void {
        $modification = $this->modification->fresh();

        if (! $modification instanceof Modification || ! $modification->active) {
            return;
        }

        $system_user_id = ai_config_int('ai.features.moderation.system_user_id');

        if ($system_user_id <= 0) {
            return;
        }

        try {
            if (! $registry->supports($modification)) {
                return;
            }

            $request = $registry->build($modification);
            $result = $service->analyze($request);

            /** @var User $system_user */
            $system_user = User::query()->findOrFail($system_user_id);

            if (! ai_config_bool('ai.features.moderation.ai_participates_in_approvals', true)) {
                return;
            }

            $approval_mode = ModerationApprovalMode::fromConfig();

            if ($approval_mode === ModerationApprovalMode::Dual) {
                $this->handleDualMode($modification, $system_user, $result);

                return;
            }

            $this->handleThresholdMode($modification, $system_user, $result);
        } catch (Throwable) {
            $this->applyUncertainFallback($modification, User::query()->find($system_user_id));
        } finally {
            event(new ModificationPreProcessingCompleted($modification, 'ai_approval'));
        }
    }

    private function handleThresholdMode(
        Modification $modification,
        User $system_user,
        ModerationResult $result,
    ): void {
        $approve_threshold = ai_config_float('ai.features.moderation.approve_confidence_threshold', 0.85);
        $reject_threshold = ai_config_float('ai.features.moderation.reject_confidence_threshold', 0.85);

        if ($result->safeToAutoApprove && $result->confidence >= $approve_threshold) {
            $modification->approvers_required = 1;
            $modification->disapprovers_required = 1;
            $modification->save();
            $this->ensureModifiableRelation($modification);
            $this->castApproval($system_user, $modification, $result->reason, $result, 'auto_approved');

            return;
        }

        if ($result->verdict === ModerationVerdict::Reject && $result->confidence >= $reject_threshold) {
            $modification->approvers_required = 1;
            $modification->disapprovers_required = 1;
            $modification->save();
            $this->ensureModifiableRelation($modification);
            $this->castDisapproval($system_user, $modification, $result->reason, $result, 'auto_rejected');

            return;
        }

        $this->applyUncertainFallback($modification, $system_user, $result);
    }

    private function handleDualMode(
        Modification $modification,
        User $system_user,
        ModerationResult $result,
    ): void {
        $modification->approvers_required = 2;
        $modification->disapprovers_required = 2;
        $modification->save();

        $this->castAiFirstVote($system_user, $modification, $result);
    }

    private function castAiFirstVote(User $system_user, Modification $modification, ModerationResult $result): void
    {
        $this->ensureModifiableRelation($modification);

        if ($result->verdict === ModerationVerdict::Approve) {
            $this->castApproval($system_user, $modification, $result->reason, $result, 'requires_human_review', [
                'requires_human_approval' => true,
                'preliminary_disapproval' => false,
            ]);

            return;
        }

        $this->castDisapproval($system_user, $modification, $result->reason, $result, 'requires_human_review', [
            'requires_human_approval' => true,
            'preliminary_disapproval' => true,
        ]);
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
        ?User $system_user,
        ?ModerationResult $result = null,
    ): void {
        if ($system_user === null) {
            return;
        }

        $modification->approvers_required = 1;
        $modification->disapprovers_required = 2;
        $modification->save();

        $reason = $result !== null
            ? 'AI preliminary reject (confidence ' . $result->confidence . '): ' . $result->reason
            : 'AI moderation failed; human review required.';

        $this->ensureModifiableRelation($modification);
        $this->castDisapproval($system_user, $modification, $reason, $result, 'requires_human_review', [
            'requires_human_approval' => true,
            'preliminary_disapproval' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function castApproval(
        User $system_user,
        Modification $modification,
        string $reason,
        ?ModerationResult $result,
        string $status,
        array $extra = [],
    ): void {
        $this->asSystemUser($system_user, function () use ($system_user, $modification, $reason): void {
            $system_user->approve($modification, $reason);
        });

        $this->attachMetaToLatestApproval($modification, $system_user, $this->buildVoteMeta($result, $status, $extra));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function castDisapproval(
        User $system_user,
        Modification $modification,
        string $reason,
        ?ModerationResult $result,
        string $status,
        array $extra = [],
    ): void {
        $this->asSystemUser($system_user, function () use ($system_user, $modification, $reason): void {
            $system_user->disapprove($modification, $reason);
        });

        $this->attachMetaToLatestDisapproval($modification, $system_user, $this->buildVoteMeta($result, $status, $extra));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function attachMetaToLatestApproval(Modification $modification, User $system_user, array $meta): void
    {
        Approval::query()
            ->where('modification_id', $modification->id)
            ->where('approver_id', $system_user->getKey())
            ->where('approver_type', $system_user->getMorphClass())
            ->orderByDesc('id')
            ->limit(1)
            ->update(['meta' => $meta]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function attachMetaToLatestDisapproval(Modification $modification, User $system_user, array $meta): void
    {
        Disapproval::query()
            ->where('modification_id', $modification->id)
            ->where('disapprover_id', $system_user->getKey())
            ->where('disapprover_type', $system_user->getMorphClass())
            ->orderByDesc('id')
            ->limit(1)
            ->update(['meta' => $meta]);
    }

    /**
     * @param  array<string, mixed>  $extra
     *
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
