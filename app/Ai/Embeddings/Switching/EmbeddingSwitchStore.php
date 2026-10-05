<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Closure;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Models\Setting;
use Modules\Core\Services\SettingsCacheCoordinator;

/**
 * Persists the {@see EmbeddingSwitchState} in the managed setting `features.embeddings.switch`,
 * suspends and resumes vector search through `search.vector.suspended_reason`, and serialises
 * switch starts with an atomic lock.
 */
final class EmbeddingSwitchStore
{
    public const string SUSPENDED_REASON = 'switching';

    private const string SETTING = 'features.embeddings.switch';

    private const string SUSPENDED_REASON_SETTING = 'search.vector.suspended_reason';

    private const string LOCK = 'embeddings:switch';

    /**
     * The lock covers only the start (the preflight's network calls, about 35 seconds at most); the
     * persisted state guards the rest of the switch.
     */
    private const int LOCK_SECONDS = 120;

    public function get(): EmbeddingSwitchState
    {
        $setting = Setting::query()->withoutGlobalScopes()->where('name', self::SETTING)->first();
        $value = $setting?->value;

        return EmbeddingSwitchState::fromJson(is_string($value) ? $value : null);
    }

    public function put(EmbeddingSwitchState $state): void
    {
        Setting::writeManaged(self::SETTING, $state->toJson());
    }

    /**
     * Suspends vector search for the switch (`search.vector.suspended_reason` = `switching`).
     */
    public function suspend(): void
    {
        Setting::writeManaged(self::SUSPENDED_REASON_SETTING, self::SUSPENDED_REASON);
    }

    /**
     * Lifts the suspension: the setting is unset again, stored as the seeder stores it, a JSON
     * `null`. `core_settings.value` is NOT NULL and `SettingObserver` turns an empty string into
     * null, so neither `null` nor `''` can go through `Setting::writeManaged()`. The settings cache
     * and the runtime config are then flushed as the observer would have done.
     */
    public function clearSuspension(): void
    {
        $setting = Setting::query()->withoutGlobalScopes()->where('name', self::SUSPENDED_REASON_SETTING)->firstOrFail();
        $setting->newQuery()->withoutGlobalScopes()->whereKey($setting->getKey())->toBase()->update(['value' => 'null']);

        app(SettingsCacheCoordinator::class)->flushSetting($setting->refresh(), sync_runtime_config: true);
    }

    /**
     * @return (Closure(): void)|null The release closure, or null when another switch holds the lock.
     */
    public function lock(): ?Closure
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        return static function () use ($lock): void {
            $lock->release();
        };
    }
}
