<?php

declare(strict_types=1);

use Modules\AI\Ai\Providers\ProviderFactory;
use Modules\AI\Exceptions\GuardrailViolationException;
use Modules\AI\Exceptions\InvalidActionRequestStateException;
use Modules\AI\Exceptions\TranslationException;
use Modules\AI\Exceptions\UnknownToolException;
use Modules\Core\Exceptions\ConfigurationException;

test('ai exceptions use the expected base types', function (): void {
    expect(UnknownToolException::class)->toExtend(InvalidArgumentException::class)
        ->and(InvalidActionRequestStateException::class)->toExtend(InvalidArgumentException::class)
        ->and(TranslationException::class)->toExtend(RuntimeException::class)
        ->and(GuardrailViolationException::class)->toExtend(RuntimeException::class);
});

test('provider factory throws invalid argument for unsupported provider', function (): void {
    ProviderFactory::make('unsupported-provider');
})->throws(InvalidArgumentException::class, 'Unsupported AI provider: unsupported-provider');

test('provider factory throws configuration exception when openai api key is missing', function (): void {
    config()->set('ai.providers.openai.api_key', '');

    ProviderFactory::make('openai');
})->throws(ConfigurationException::class, 'OpenAI API key is not configured');

test('translation exception exposes http status code', function (): void {
    $exception = new TranslationException('DeepL translation failed: 429', 429);

    expect($exception->statusCode)->toBe(429)
        ->and($exception->getMessage())->toBe('DeepL translation failed: 429');
});
