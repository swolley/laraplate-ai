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

    private const string TARGET_SETTING = 'features.embeddings.model';

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
     * Suspends vector search for the switch (`search.vector.suspended_reason` = `switching`). The
     * settings cache is flushed again once the surrounding transaction commits, so a read made
     * before the commit cannot keep the old value cached.
     */
    public function suspend(): void
    {
        Setting::writeManaged(self::SUSPENDED_REASON_SETTING, self::SUSPENDED_REASON);

        $this->flushAfterCommit([self::SUSPENDED_REASON_SETTING]);
    }

    /**
     * Lifts the suspension: the setting is unset again, stored as the seeder stores it, a JSON
     * `null`. `core_settings.value` is NOT NULL and `SettingObserver` turns an empty string into
     * null, so neither `null` nor `''` can go through `Setting::writeManaged()`. The settings cache
     * and the runtime config are flushed once the surrounding transaction commits (at once outside
     * one). A missing row means there is nothing to clear.
     */
    public function clearSuspension(): void
    {
        Setting::query()->withoutGlobalScopes()
            ->where('name', self::SUSPENDED_REASON_SETTING)
            ->toBase()
            ->update(['value' => 'null']);

        $this->flushAfterCommit([self::SUSPENDED_REASON_SETTING]);
    }

    /**
     * Records `$key` as the model the operator chose (`features.embeddings.model`, the dropdown),
     * so the settings page names the model the switch ended on. The setting is not managed, and a
     * plain save would go through approval; it is written directly and flushed like the suspension.
     */
    public function recordTarget(string $key): void
    {
        Setting::query()->withoutGlobalScopes()
            ->where('name', self::TARGET_SETTING)
            ->toBase()
            ->update(['value' => json_encode($key, JSON_THROW_ON_ERROR)]);

        $this->flushAfterCommit([self::TARGET_SETTING]);
    }

    /**
     * Flushes the settings cache and the runtime config of the named settings once the surrounding
     * transaction commits (at once outside one), so a read made before the commit cannot keep the
     * old value cached.
     *
     * @param  list<string>  $names
     */
    public function flushAfterCommit(array $names): void
    {
        new Setting()->getConnection()->afterCommit(function () use ($names): void {
            $this->resync($names);
        });
    }

    /**
     * Reloads the named settings and flushes their cache and runtime config now: after a rolled
     * back write, the observer has already pushed the value that never reached the database.
     *
     * @param  list<string>  $names
     */
    public function resync(array $names): void
    {
        $coordinator = app(SettingsCacheCoordinator::class);

        foreach (Setting::query()->withoutGlobalScopes()->whereIn('name', $names)->get() as $setting) {
            $coordinator->flushSetting($setting, sync_runtime_config: true);
        }
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
