<?php

declare(strict_types=1);

namespace Modules\AI\Services\Translation;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\Translation\TranslationServiceInterface;

final class DeepLTranslationService implements TranslationServiceInterface
{
    private readonly string $api_key;

    private string $api_url = 'https://api-free.deepl.com/v2/translate';

    public function __construct()
    {
        $this->api_key = config('core.deepl_api_key', '');

        throw_if($this->api_key === '' || $this->api_key === '0', Exception::class, 'DeepL API key is not configured');

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
                $data = $response->json();

                return $data['translations'][0]['text'] ?? $text;
            }

            Log::warning('DeepL translation failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new Exception('DeepL translation failed: ' . $response->status());
        } catch (Exception $e) {
            Log::error('DeepL translation error', [
                'error' => $e->getMessage(),
                'from' => $from_locale,
                'to' => $to_locale,
            ]);

            throw $e;
        }
    }

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
                $data = $response->json();
                $translations = [];

                foreach ($data['translations'] ?? [] as $index => $translation) {
                    $translations[$index] = $translation['text'] ?? $texts[$index] ?? '';
                }

                return $translations;
            }

            Log::warning('DeepL batch translation failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new Exception('DeepL batch translation failed: ' . $response->status());
        } catch (Exception $e) {
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
}
