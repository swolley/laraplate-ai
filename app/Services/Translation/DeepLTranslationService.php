<?php

declare(strict_types=1);

namespace Modules\AI\Services\Translation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\AI\Exceptions\TranslationException;
use Modules\Core\Exceptions\ConfigurationException;
use Throwable;

use function ai_config_string;

final class DeepLTranslationService implements TranslationServiceInterface
{
    private readonly string $api_key;

    private string $api_url = 'https://api-free.deepl.com/v2/translate';

    public function __construct()
    {
        $this->api_key = ai_config_string('core.deepl_api_key');

        throw_if($this->api_key === '' || $this->api_key === '0', ConfigurationException::class, 'DeepL API key is not configured');

        // Use pro API if key starts with specific pattern
        if (str_starts_with($this->api_key, 'fx-')) {
            $this->api_url = 'https://api.deepl.com/v2/translate';
        }
    }

    public function translate(string $text, string $from_locale, string $to_locale): string
    {
        if ($text === '' || $text === '0') {
            return $text;
        }

        try {
            $response = Http::timeout(30)
                ->asForm()
                ->post($this->api_url, [
                    'auth_key' => $this->api_key,
                    'text' => $text,
                    'source_lang' => $this->mapLocale($from_locale),
                    'target_lang' => $this->mapLocale($to_locale),
                ]);

            if ($response->successful()) {
                return $this->extractSingleTranslation($response->json(), $text);
            }

            Log::warning('DeepL translation failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new TranslationException('DeepL translation failed: ' . $response->status(), $response->status());
        } catch (Throwable $e) {
            Log::error('DeepL translation error', [
                'error' => $e->getMessage(),
                'from' => $from_locale,
                'to' => $to_locale,
            ]);

            throw $e;
        }
    }

    /**
     * @param  list<string>  $texts
     * @return list<string>
     */
    public function translateBatch(array $texts, string $from_locale, string $to_locale): array
    {
        if ($texts === []) {
            return [];
        }

        try {
            $response = Http::timeout(60)
                ->asForm()
                ->post($this->api_url, [
                    'auth_key' => $this->api_key,
                    'text' => $texts,
                    'source_lang' => $this->mapLocale($from_locale),
                    'target_lang' => $this->mapLocale($to_locale),
                ]);

            if ($response->successful()) {
                return $this->extractBatchTranslations($response->json(), $texts);
            }

            Log::warning('DeepL batch translation failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new TranslationException('DeepL batch translation failed: ' . $response->status(), $response->status());
        } catch (Throwable $e) {
            Log::error('DeepL batch translation error', [
                'error' => $e->getMessage(),
                'from' => $from_locale,
                'to' => $to_locale,
            ]);

            throw $e;
        }
    }

    /**
     * Map Laravel locale to DeepL language code.
     */
    private function mapLocale(string $locale): string
    {
        return match ($locale) {
            'en' => 'EN',
            'it' => 'IT',
            'fr' => 'FR',
            'de' => 'DE',
            'es' => 'ES',
            'pt' => 'PT',
            'ru' => 'RU',
            'ja' => 'JA',
            'zh' => 'ZH',
            default => mb_strtoupper($locale),
        };
    }

    private function extractSingleTranslation(mixed $payload, string $fallback): string
    {
        $translations = $this->parseTranslations($payload);

        return $translations[0] ?? $fallback;
    }

    /**
     * @param  list<string>  $fallback_texts
     * @return list<string>
     */
    private function extractBatchTranslations(mixed $payload, array $fallback_texts): array
    {
        $translations = $this->parseTranslations($payload);
        $results = [];

        foreach ($fallback_texts as $index => $fallback_text) {
            $results[] = $translations[$index] ?? $fallback_text;
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function parseTranslations(mixed $payload): array
    {
        if (! is_array($payload) || ! isset($payload['translations']) || ! is_array($payload['translations'])) {
            return [];
        }

        $translations = [];

        foreach ($payload['translations'] as $translation) {
            if (! is_array($translation)) {
                continue;
            }

            $text = $translation['text'] ?? null;
            $translations[] = is_string($text) ? $text : '';
        }

        return $translations;
    }
}
