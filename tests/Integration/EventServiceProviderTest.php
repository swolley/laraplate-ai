<?php

declare(strict_types=1);

use Modules\AI\Listeners\HandleBulkModelIndexingListener;
use Modules\AI\Listeners\HandleModelIndexingListener;
use Modules\AI\Listeners\HandleModelTranslationListener;
use Modules\AI\Listeners\HandleTranslationReembeddingListener;
use Modules\AI\Providers\EventServiceProvider;
use Modules\Core\Events\AiTextGenerationRequested;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Events\ModelsRequireIndexing;
use Modules\Core\Events\TranslatedModelSaved;
use Modules\Core\Events\TranslationRequiresReembedding;

it('has correct listen property mapping', function (): void {
    $provider = new EventServiceProvider(app());
    $reflection = new ReflectionClass($provider);
    $property = $reflection->getProperty('listen');
    $listen = $property->getValue($provider);

    expect($listen)->toHaveKey(ModelRequiresIndexing::class)
        ->and($listen)->toHaveKey(TranslatedModelSaved::class);
});

it('uses ModelRequiresIndexing with HandleModelIndexingListener', function (): void {
    $provider = new EventServiceProvider(app());
    $reflection = new ReflectionClass($provider);
    $property = $reflection->getProperty('listen');
    $listen = $property->getValue($provider);

    expect($listen[ModelRequiresIndexing::class])->toContain(HandleModelIndexingListener::class);
});

it('uses ModelsRequireIndexing with HandleBulkModelIndexingListener', function (): void {
    $provider = new EventServiceProvider(app());
    $reflection = new ReflectionClass($provider);
    $property = $reflection->getProperty('listen');
    $listen = $property->getValue($provider);

    expect($listen[ModelsRequireIndexing::class])->toContain(HandleBulkModelIndexingListener::class);
});

it('uses TranslatedModelSaved with HandleModelTranslationListener', function (): void {
    $provider = new EventServiceProvider(app());
    $reflection = new ReflectionClass($provider);
    $property = $reflection->getProperty('listen');
    $listen = $property->getValue($provider);

    expect($listen[TranslatedModelSaved::class])->toContain(HandleModelTranslationListener::class);
});

it('uses TranslationRequiresReembedding with HandleTranslationReembeddingListener', function (): void {
    $provider = new EventServiceProvider(app());
    $reflection = new ReflectionClass($provider);
    $property = $reflection->getProperty('listen');
    $listen = $property->getValue($provider);

    expect($listen[TranslationRequiresReembedding::class])->toContain(HandleTranslationReembeddingListener::class);
});

it('registers a listener mapping for every handled event', function (): void {
    $provider = new EventServiceProvider(app());
    $reflection = new ReflectionClass($provider);
    $property = $reflection->getProperty('listen');
    $listen = $property->getValue($provider);

    expect($listen)->toHaveCount(7)
        ->and(array_keys($listen))->toContain(ModelRequiresIndexing::class)
        ->and(array_keys($listen))->toContain(ModelsRequireIndexing::class)
        ->and(array_keys($listen))->toContain(TranslatedModelSaved::class)
        ->and(array_keys($listen))->toContain(TranslationRequiresReembedding::class)
        ->and(array_keys($listen))->toContain(AiTextGenerationRequested::class);
});

it('registers and boots without error', function (): void {
    $provider = new EventServiceProvider(app());
    $provider->register();
    $provider->boot();

    expect(true)->toBeTrue();
});

it('configureEmailVerification executes without error', function (): void {
    $provider = new EventServiceProvider(app());
    $method = new ReflectionMethod($provider, 'configureEmailVerification');
    $method->invoke($provider);

    expect(true)->toBeTrue();
});

it('shouldDiscoverEvents is enabled', function (): void {
    $reflection = new ReflectionClass(EventServiceProvider::class);
    $property = $reflection->getProperty('shouldDiscoverEvents');

    expect($property->getValue())->toBeTrue();
});
