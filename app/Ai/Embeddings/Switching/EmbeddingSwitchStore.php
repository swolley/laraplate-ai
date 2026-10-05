<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Closure;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Models\Setting;

/**
 * Persists the {@see EmbeddingSwitchState} in the managed setting `features.embeddings.switch`
 * and serialises switches with an atomic lock.
 */
final class EmbeddingSwitchStore
{
    private const string SETTING = 'features.embeddings.switch';

    private const string LOCK = 'embeddings:switch';

    private const int LOCK_SECONDS = 6 * 3600;

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
