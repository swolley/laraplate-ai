<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\AI\Services\Translation\TranslationService;

it('constructor initializes with deepl provider by default', function (): void {
    config()->set('ai.features.translation.model', 'deepl');
    config()->set('core.deepl_api_key', 'test-key');

    Http::fake([
        'https://api-free.deepl.com/v2/translate' => Http::response([
            'translations' => [['text' => 'Translated']],
        ], 200),
    ]);

    $service = new TranslationService;

    $result = $service->translate('hello', 'en', 'it');

    expect($result)->toBe('Translated');
});

it('translate caches results', function (): void {
    config()->set('ai.features.translation.model', 'deepl');
    config()->set('core.deepl_api_key', 'test-key');
    config()->set('core.translations.cache.enabled', true);

    Http::fake([
        'https://api-free.deepl.com/v2/translate' => Http::response([
            'translations' => [['text' => 'Cached result']],
        ], 200),
    ]);

    Cache::flush();

    $service = new TranslationService;

    $result1 = $service->translate('cache me', 'en', 'it');
    $result2 = $service->translate('cache me', 'en', 'it');

    expect($result1)->toBe('Cached result')
        ->and($result2)->toBe('Cached result');

    Http::assertSentCount(1);
});

it('translate returns empty and zero text as-is', function (): void {
    config()->set('ai.features.translation.model', 'deepl');
    config()->set('core.deepl_api_key', 'test-key');

    $service = new TranslationService;

    expect($service->translate('', 'en', 'it'))->toBe('')
        ->and($service->translate('0', 'en', 'it'))->toBe('0');
});

it('translateBatch translates each text', function (): void {
    config()->set('ai.features.translation.model', 'deepl');
    config()->set('core.deepl_api_key', 'test-key');

    Http::fake([
        'https://api-free.deepl.com/v2/translate' => Http::sequence()
            ->push(['translations' => [['text' => 'Uno']]], 200)
            ->push(['translations' => [['text' => 'Due']]], 200),
    ]);

    $service = new TranslationService;

    $result = $service->translateBatch(['One', 'Two'], 'en', 'it');

    expect($result)->toBe(['Uno', 'Due']);
});

it('translateBatch returns empty array when texts is empty', function (): void {
    config()->set('ai.features.translation.model', 'deepl');
    config()->set('core.deepl_api_key', 'test-key');

    $service = new TranslationService;

    $result = $service->translateBatch([], 'en', 'it');

    expect($result)->toBe([]);
});

it('builds the AI translator on the translation choice', function (): void {
    config()->set('ai.features.translation.model', 'ollama:phi3');

    $service = new TranslationService;
    $inner = (new ReflectionProperty($service, 'service'))->getValue($service);

    expect($inner)->toBeInstanceOf(Modules\AI\Services\Translation\AiTranslationService::class)
        ->and((new ReflectionProperty($inner, 'provider'))->getValue($inner))->toBe('ollama')
        ->and((new ReflectionProperty($inner, 'model'))->getValue($inner))->toBe('phi3');
});

it('propagates a provider failure and caches nothing', function (): void {
    config()->set('ai.features.translation.model', 'deepl');
    config()->set('core.deepl_api_key', 'test-key');
    config()->set('core.translations.cache.enabled', true);
    Cache::flush();

    Http::fake(['https://api-free.deepl.com/v2/translate' => Http::sequence()
        ->push(null, 500)
        ->push(['translations' => [['text' => 'Tradotto']]], 200)]);

    expect(fn (): string => (new TranslationService)->translate('original text', 'en', 'it'))->toThrow(Exception::class);

    expect((new TranslationService)->translate('original text', 'en', 'it'))->toBe('Tradotto');
});
