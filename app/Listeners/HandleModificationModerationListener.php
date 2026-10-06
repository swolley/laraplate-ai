<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use function ai_config_bool;
use function ai_config_string;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\AI\Jobs\ApproveModificationJob;
use Modules\AI\Services\ModerationEntitySettings;
use Modules\AI\Services\ModerationSystemUser;
use Modules\Core\Events\ModificationRequiresModeration;
use Modules\Core\Models\Modification;
use Modules\Core\Models\User;
use Modules\Core\Services\ModerationAdapterRegistry;

final class HandleModificationModerationListener
{
    public function __construct(
        private readonly ModerationAdapterRegistry $registry,
        private readonly ModerationSystemUser $system_users,
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

        if (! $this->system_users->resolve() instanceof User) {
            Log::warning('AI moderation skipped: no user carries the configured system username (permission.users.system).');

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
        return ModerationEntitySettings::enabledFor($model);
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

        Cache::put(ModificationRequiresModeration::cacheKey($event->modification), $event, now()->addMinutes(ModificationRequiresModeration::CACHE_TTL_MINUTES));
    }
}
