<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\AI\Services\Translation\DeepLTranslationService;

beforeEach(function (): void {
    config()->set('core.deepl_api_key', 'test-key');
});

it('constructor throws if API key is empty', function (): void {
    config()->set('core.deepl_api_key', '');

    new DeepLTranslationService;
})->throws(Exception::class, 'DeepL API key is not configured');

it('constructor throws if API key is zero', function (): void {
    config()->set('core.deepl_api_key', '0');

    new DeepLTranslationService;
})->throws(Exception::class, 'DeepL API key is not configured');

it('translate returns translated text on successful response', function (): void {
    Http::fake([
        'https://api-free.deepl.com/v2/translate' => Http::response([
            'translations' => [['text' => 'Ciao mondo']],
        ], 200),
    ]);

    $service = new DeepLTranslationService;

    $result = $service->translate('Hello world', 'en', 'it');

    expect($result)->toBe('Ciao mondo');

    Http::assertSentCount(1);
});

it('translate returns original text on empty input', function (): void {
    $service = new DeepLTranslationService;

    expect($service->translate('', 'en', 'it'))->toBe('');
});

it('translate returns zero as-is', function (): void {
    $service = new DeepLTranslationService;

    expect($service->translate('0', 'en', 'it'))->toBe('0');
});

it('translateBatch returns array of translations', function (): void {
    Http::fake([
        'https://api-free.deepl.com/v2/translate' => Http::response([
            'translations' => [
                ['text' => 'Ciao'],
                ['text' => 'Mondo'],
            ],
        ], 200),
    ]);

    $service = new DeepLTranslationService;

    $result = $service->translateBatch(['Hello', 'World'], 'en', 'it');

    expect($result)->toBe(['Ciao', 'Mondo']);
});

it('translateBatch returns empty array for empty input', function (): void {
    $service = new DeepLTranslationService;

    $result = $service->translateBatch([], 'en', 'it');

    expect($result)->toBe([]);
});

it('parseTranslations returns empty array for unexpected payloads', function (): void {
    $service = new DeepLTranslationService;
    $method = new ReflectionMethod($service, 'parseTranslations');

    expect($method->invoke($service, ['unexpected' => true]))->toBe([]);
});

it('parseTranslations ignores non-array translation entries', function (): void {
    $service = new DeepLTranslationService;
    $method = new ReflectionMethod($service, 'parseTranslations');

    expect($method->invoke($service, [
        'translations' => [
            'invalid',
            ['text' => 'Ciao'],
        ],
    ]))->toBe(['Ciao']);
});

it('uses pro API URL when key starts with fx-', function (): void {
    config()->set('core.deepl_api_key', 'fx-test-key');

    Http::fake([
        'https://api.deepl.com/v2/translate' => Http::response([
            'translations' => [['text' => 'Translated']],
        ], 200),
    ]);

    $service = new DeepLTranslationService;

    $result = $service->translate('test', 'en', 'it');

    expect($result)->toBe('Translated');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.deepl.com/v2/translate');
});

it('translate throws on failed response', function (): void {
    Http::fake([
        'https://api-free.deepl.com/v2/translate' => Http::response(null, 500),
    ]);

    $service = new DeepLTranslationService;

    $service->translate('hello', 'en', 'it');
})->throws(Exception::class, 'DeepL translation failed');

it('translateBatch fails on HTTP 500 and throws', function (): void {
    Http::fake([
        'https://api-free.deepl.com/v2/translate' => Http::response(null, 500),
    ]);

    $service = new DeepLTranslationService;

    $service->translateBatch(['Hello', 'World'], 'en', 'it');
})->throws(Exception::class, 'DeepL batch translation failed');

it('mapLocale maps fr locale correctly', function (): void {
    Http::fake([
        'https://api-free.deepl.com/v2/translate' => Http::response([
            'translations' => [['text' => 'Bonjour']],
        ], 200),
    ]);

    $service = new DeepLTranslationService;
    $result = $service->translate('Hello', 'en', 'fr');

    expect($result)->toBe('Bonjour');
    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return ($body['target_lang'] ?? '') === 'FR' && ($body['source_lang'] ?? '') === 'EN';
    });
});

it('mapLocale maps de locale correctly', function (): void {
    Http::fake([
        'https://api-free.deepl.com/v2/translate' => Http::response([
            'translations' => [['text' => 'Hallo']],
        ], 200),
    ]);

    $service = new DeepLTranslationService;
    $result = $service->translate('Hello', 'fr', 'de');

    expect($result)->toBe('Hallo');
    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return ($body['target_lang'] ?? '') === 'DE' && ($body['source_lang'] ?? '') === 'FR';
    });
});

it('mapLocale maps es pt ru ja zh locales correctly', function (): void {
    Http::fake([
        'https://api-free.deepl.com/v2/translate' => Http::response([
            'translations' => [['text' => 'Translated']],
        ], 200),
    ]);

    $service = new DeepLTranslationService;

    $service->translate('test', 'en', 'es');
    $service->translate('test', 'en', 'pt');
    $service->translate('test', 'en', 'ru');
    $service->translate('test', 'en', 'ja');
    $service->translate('test', 'en', 'zh');

    Http::assertSentCount(5);
});
