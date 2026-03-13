<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\AI\Services\Translation\TranslationService;

it('constructor initializes with deepl provider by default', function (): void {
    config()->set('core.auto_translate_provider', 'deepl');
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

it('constructor initializes with ai provider', function (): void {
    config()->set('core.auto_translate_provider', 'ai');

    $service = new TranslationService;

    expect($service->translate('', 'en', 'it'))->toBe('');
});

it('constructor throws on unsupported provider', function (): void {
    config()->set('core.auto_translate_provider', 'unsupported');
    config()->set('core.deepl_api_key', 'test-key');

    new TranslationService;
})->throws(Exception::class, 'Unsupported translation provider');

it('translate caches results', function (): void {
    config()->set('core.auto_translate_provider', 'deepl');
    config()->set('core.deepl_api_key', 'test-key');
    config()->set('core.translation_cache_enabled', true);

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
    config()->set('core.auto_translate_provider', 'deepl');
    config()->set('core.deepl_api_key', 'test-key');

    $service = new TranslationService;

    expect($service->translate('', 'en', 'it'))->toBe('')
        ->and($service->translate('0', 'en', 'it'))->toBe('0');
});

it('translateBatch translates each text', function (): void {
    config()->set('core.auto_translate_provider', 'deepl');
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

it('translate returns original when primary fails and fallback is disabled', function (): void {
    config()->set('core.auto_translate_provider', 'deepl');
    config()->set('core.deepl_api_key', 'test-key');
    config()->set('core.auto_translate_fallback_to_ai', false);
    config()->set('core.translation_cache_enabled', false);

    Http::fake([
        'https://api-free.deepl.com/v2/translate' => Http::response(null, 500),
    ]);

    $service = new TranslationService;

    $result = $service->translate('original text', 'en', 'it');

    expect($result)->toBe('original text');
});

it('translateBatch returns empty array when texts is empty', function (): void {
    config()->set('core.auto_translate_provider', 'deepl');
    config()->set('core.deepl_api_key', 'test-key');

    $service = new TranslationService;

    $result = $service->translateBatch([], 'en', 'it');

    expect($result)->toBe([]);
});

it('translate returns original text when both primary and fallback fail', function (): void {
    config()->set('core.auto_translate_provider', 'deepl');
    config()->set('core.deepl_api_key', 'test-key');
    config()->set('core.auto_translate_fallback_to_ai', true);
    config()->set('core.translation_cache_enabled', false);

    Http::fake(fn () => Http::response(null, 500));

    $service = new TranslationService;

    $result = $service->translate('original text', 'en', 'it');

    expect($result)->toBe('original text');
})->skip('TranslationService creates AiTranslationService with new; fallback ChatAgent requires Workflow initialization not available in unit test');
