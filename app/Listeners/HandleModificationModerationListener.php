<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\AI\Jobs\ApproveModificationJob;
use Modules\Core\Events\ModificationRequiresModeration;
use Modules\Core\Helpers\HasApprovals;
use Modules\Core\Models\Modification;
use Modules\Core\Services\ModerationAdapterRegistry;

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

        $queue = (string) config('ai.features.moderation.queue', 'default');

        dispatch(new ApproveModificationJob($event->modification))->onQueue($queue);

        $event->markAsHandled();
    }

    private function shouldHandle(Modification $modification): bool
    {
        if (! config('ai.features.moderation.enabled', true)) {
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

        if ($modifiable !== null) {
            return class_uses_trait($modifiable, HasApprovals::class)
                && $modifiable->aiModerationEnabledBySettings();
        }

        $modifiable_class = $modification->modifiable_type;

        if (! is_string($modifiable_class) || ! class_exists($modifiable_class)) {
            return false;
        }

        $instance = new $modifiable_class();

        if (! class_uses_trait($instance, HasApprovals::class)) {
            return false;
        }

        return $instance->aiModerationEnabledBySettings();
    }

    private function saveEventToCache(ModificationRequiresModeration $event): void
    {
        if ($event->sync) {
            return;
        }

        $cache_key = 'modification_moderation:' . $event->modification->getKey();
        Cache::put($cache_key, $event, now()->addMinutes(10));
    }
}
