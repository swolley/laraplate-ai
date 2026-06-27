<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\AI\Jobs\ApproveModificationJob;
use Modules\Core\Events\ModificationRequiresModeration;
use Modules\Core\Contracts\IModeratableModel;
use Modules\Core\Models\Concerns\HasApprovals;
use Modules\Core\Models\Modification;
use Modules\Core\Services\ModerationAdapterRegistry;

use function ai_config_bool;
use function ai_config_string;

final class HandleModificationModerationListener
{
    public function __construct(
        private readonly ModerationAdapterRegistry $registry,
    ) {}

    public function handle(ModificationRequiresModeration $event): void
    {
        if (! $this->shouldHandle($event->modification)) {
            return;
        }

        $event->addRequiredPreProcessing('ai_approval');
        $this->saveEventToCache($event);

        $queue = ai_config_string('ai.features.moderation.queue', 'default');

        dispatch(new ApproveModificationJob($event->modification))->onQueue($queue);

        $event->markAsHandled();
    }

    private function shouldHandle(Modification $modification): bool
    {
        if (! ai_config_bool('ai.features.moderation.enabled', true)) {
            return false;
        }

        $system_user_id = config('permission.users.system');

        if ($system_user_id === null || $system_user_id === '') {
            Log::warning('AI moderation skipped: system_user_id is not configured.');

            return false;
        }

        if (! $modification->active) {
            return false;
        }

        if (! $this->registry->supports($modification)) {
            return false;
        }

        return $this->modifiableSupportsAiModeration($modification);
    }

    private function modifiableSupportsAiModeration(Modification $modification): bool
    {
        $modifiable = $modification->modifiable;

        if ($modifiable instanceof Model) {
            return $this->supportsAiModeration($modifiable);
        }

        $modifiable_class = $modification->modifiable_type;

        if (! is_string($modifiable_class) || ! class_exists($modifiable_class)) {
            return false;
        }

        $instance = new $modifiable_class();

        if (! $instance instanceof Model) {
            return false;
        }

        return $this->supportsAiModeration($instance);
    }

    private function supportsAiModeration(Model $model): bool
    {
        if (! $this->usesApprovalsTrait($model)) {
            return false;
        }

        return $model->aiModerationEnabledBySettings();
    }

    /**
     * @phpstan-assert-if-true IModeratableModel&Model $model
     */
    private function usesApprovalsTrait(Model $model): bool
    {
        return in_array(HasApprovals::class, class_uses_recursive($model), true);
    }

    private function saveEventToCache(ModificationRequiresModeration $event): void
    {
        if ($event->sync) {
            return;
        }

        $modification_key = $event->modification->getKey();

        if (! is_int($modification_key) && ! is_string($modification_key)) {
            return;
        }

        $cache_key = 'modification_moderation:' . $modification_key;
        Cache::put($cache_key, $event, now()->addMinutes(10));
    }
}
