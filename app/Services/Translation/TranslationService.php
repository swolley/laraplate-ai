<?php

declare(strict_types=1);

namespace Modules\AI\Services\Translation;

use function ai_config_bool;

use Illuminate\Support\Facades\Cache;
use Modules\AI\Ai\Providers\AiModelChoice;
use Modules\AI\Enums\AiModelFeature;

final class TranslationService implements TranslationServiceInterface
{
    private readonly TranslationServiceInterface $service;

    private readonly bool $cache_enabled;

    /**
     * In-memory cache for translations during the request.
     *
     * @var array<string, string>
     */
    private array $memory_cache = [];

    /**
     * DeepL or an AI model, as chosen in Settings. No fallback: a failure propagates, so
     * nothing is cached and the queued translation is retried.
     */
    public function __construct()
    {
        $choice = AiModelChoice::forFeature(AiModelFeature::Translation);
        $this->cache_enabled = ai_config_bool('core.translations.cache.enabled', true);

        $this->service = $choice->provider === 'deepl'
            ? new DeepLTranslationService()
            : new AiTranslationService(provider: $choice->provider, model: $choice->model);
    }

    public function translate(string $text, string $from_locale, string $to_locale): string
    {
        if ($text === '' || $text === '0') {
            return $text;
        }

        $cache_key = $this->getCacheKey($text, $from_locale, $to_locale);

        // Check in-memory cache first
        if (isset($this->memory_cache[$cache_key])) {
            return $this->memory_cache[$cache_key];
        }

        // Check cache
        if (! $this->cache_enabled) {
            $translated = $this->performTranslation($text, $from_locale, $to_locale);
            // Store in memory even if external cache is disabled
            $this->memory_cache[$cache_key] = $translated;

            return $translated;
        }

        $translated = Cache::remember($cache_key, now()->addDays(30), fn (): string => $this->performTranslation($text, $from_locale, $to_locale));

        // Store in memory
        $this->memory_cache[$cache_key] = $translated;

        return $translated;
    }

    public function translateBatch(array $texts, string $from_locale, string $to_locale): array
    {
        if ($texts === []) {
            return [];
        }

        $translations = [];

        foreach ($texts as $text) {
            $translations[] = $this->translate($text, $from_locale, $to_locale);
        }

        return $translations;
    }

    private function performTranslation(string $text, string $from_locale, string $to_locale): string
    {
        return $this->service->translate($text, $from_locale, $to_locale);
    }

    private function getCacheKey(string $text, string $from_locale, string $to_locale): string
    {
        return 'translation:' . md5($text . $from_locale . $to_locale);
    }
}
