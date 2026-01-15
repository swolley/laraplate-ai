<?php

declare(strict_types=1);

namespace Modules\AI\Services\Translation;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class TranslationService implements TranslationServiceInterface
{
    private readonly TranslationServiceInterface $primary_service;

    private ?TranslationServiceInterface $fallback_service = null;

    private readonly bool $cache_enabled;

    /**
     * In-memory cache for translations during the request.
     */
    private array $memory_cache = [];

    public function __construct()
    {
        $provider = config('core.auto_translate_provider', 'deepl');
        $this->cache_enabled = config('core.translation_cache_enabled', true);

        // Initialize primary service
        $this->primary_service = match ($provider) {
            'deepl' => new DeepLTranslationService(),
            'ai' => new AiTranslationService(),
            default => throw new Exception("Unsupported translation provider: {$provider}"),
        };

        // Initialize fallback service if enabled
        if (config('core.auto_translate_fallback_to_ai', true) && $provider !== 'ai') {
            $this->fallback_service = new AiTranslationService();
        }
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
        try {
            return $this->primary_service->translate($text, $from_locale, $to_locale);
        } catch (Exception $e) {
            Log::warning('Primary translation service failed, trying fallback', [
                'error' => $e->getMessage(),
                'from' => $from_locale,
                'to' => $to_locale,
            ]);

            if ($this->fallback_service instanceof TranslationServiceInterface) {
                try {
                    return $this->fallback_service->translate($text, $from_locale, $to_locale);
                } catch (Exception $fallback_error) {
                    Log::error('Fallback translation service also failed', [
                        'error' => $fallback_error->getMessage(),
                        'from' => $from_locale,
                        'to' => $to_locale,
                    ]);
                }
            }

            return $text;
        }
    }

    private function getCacheKey(string $text, string $from_locale, string $to_locale): string
    {
        return 'translation:' . md5($text . $from_locale . $to_locale);
    }
}
